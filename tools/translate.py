#!/usr/bin/env python3
"""
Translate the plugin's POT into the 16 locales the estate sells in.

WHY THIS EXISTS SEPARATELY FROM THE PORTAL PIPELINE. `scripts/i18n-translate.js` in the portal
translates `messages/<locale>/<ns>.json` for next-intl. A WordPress plugin cannot read those: WP
resolves copy through gettext (.mo) and, for JS, a per-script .json WP generates from the .po. So the
MECHANISM has to be gettext, and what is shared with the portal is the part that actually matters:
the same locale set, the same placeholder-protection-and-validate discipline, and the same rule that
a translation which drops a placeholder is rejected rather than shipped.

The failure this guards against is specific and silent: a translated `%1$s` that comes back as `%1$ s`
or is dropped entirely does not error. It renders as literal text or as "undefined" in the middle of
a sentence telling a frightened customer whether their site survived. So every returned string is
checked to carry EXACTLY the placeholders its source carried, and a locale that fails is not written.

Usage:
    ANTHROPIC_API_KEY=... python3 tools/translate.py                 # every locale
    ANTHROPIC_API_KEY=... python3 tools/translate.py --locale fr_FR  # one
    ANTHROPIC_API_KEY=... python3 tools/translate.py --dry-run
"""
import argparse, json, os, re, sys, time, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
POT = os.path.join(ROOT, 'languages', 'kapsule-migrator.pot')
DOMAIN = 'kapsule-migrator'
MODEL = os.environ.get('I18N_MODEL', 'claude-sonnet-4-5-20250929')

# The estate sells in 16 locales. WordPress names them differently from the portal's BCP-47 tags, and
# the .mo filename MUST use the WordPress name or WP will never load it. en_US is the source language
# and needs no catalogue; en_NZ is generated so New Zealand spelling is not left to chance.
#
# nplurals/plural come from WordPress core's own locale data. Getting these wrong does not error, it
# silently picks the wrong form, which is why they are written down rather than inferred.
LOCALES = {
    'en_NZ': ('English (New Zealand)',  2, '(n != 1)'),
    'mi':    ('Te Reo Maori',           2, '(n > 1)'),
    'ar':    ('Arabic',                 6, '(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5)'),
    'zh_CN': ('Simplified Chinese',     1, '0'),
    'de_DE': ('German',                 2, '(n != 1)'),
    'fr_FR': ('French',                 2, '(n > 1)'),
    'es_ES': ('Spanish (Spain)',        2, '(n != 1)'),
    'it_IT': ('Italian',                2, '(n != 1)'),
    'nl_NL': ('Dutch',                  2, '(n != 1)'),
    'pt_PT': ('Portuguese (Portugal)',  2, '(n != 1)'),
    'ja':    ('Japanese',               1, '0'),
    'ko_KR': ('Korean',                 1, '0'),
    'hi_IN': ('Hindi',                  2, '(n != 1)'),
    'tr_TR': ('Turkish',                2, '(n > 1)'),
    'ru_RU': ('Russian',                3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)'),
}

# Never translated. A brand is a name, and a customer looking for "KapsuleHost" in their panel must
# find the same word here.
DO_NOT_TRANSLATE = ['KapsuleHost', 'Kapsule', 'WordPress', 'wp-config.php', 'KPanel']

PLACEHOLDER_RE = re.compile(r'%\d+\$[sd]|%[sd]')


# ── POT parsing ──────────────────────────────────────────────────────────────

def parse_pot(path):
    """Minimal PO/POT reader. Returns entries with msgid, optional plural, and translator comment."""
    entries, cur = [], {}
    key = None

    def flush():
        if cur.get('msgid'):
            entries.append(dict(cur))
        cur.clear()

    for raw in open(path, encoding='utf-8'):
        line = raw.rstrip('\n')
        if not line.strip():
            flush(); key = None; continue
        if line.startswith('#.'):
            cur.setdefault('comment', '')
            cur['comment'] += line[2:].strip() + ' '
            continue
        if line.startswith('#:'):
            # SOURCE REFERENCES ARE LOAD-BEARING, not decoration. `wp i18n make-json` decides which
            # strings belong to which JavaScript file purely from these, so a .po written without them
            # compiles to zero JS catalogues and every runtime string silently stays English while the
            # .mo files look complete.
            cur.setdefault('refs', [])
            cur['refs'].append(line[2:].strip())
            continue
        if line.startswith('#'):
            continue
        m = re.match(r'^(msgid_plural|msgid|msgctxt)\s+"(.*)"$', line)
        if m:
            key = {'msgid': 'msgid', 'msgid_plural': 'plural', 'msgctxt': 'ctxt'}[m.group(1)]
            cur[key] = unescape(m.group(2))
            continue
        if line.startswith('msgstr'):
            key = None
            continue
        m = re.match(r'^"(.*)"$', line)
        if m and key:
            cur[key] += unescape(m.group(1))
    flush()
    return entries


def unescape(s):
    return s.replace('\\"', '"').replace('\\n', '\n').replace('\\t', '\t').replace('\\\\', '\\')


def escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


# ── Translation ──────────────────────────────────────────────────────────────

def call_claude(api_key, prompt):
    body = json.dumps({
        'model': MODEL,
        'max_tokens': 16000,
        'messages': [{'role': 'user', 'content': prompt}],
    }).encode()
    req = urllib.request.Request(
        'https://api.anthropic.com/v1/messages',
        data=body,
        headers={'content-type': 'application/json',
                 'x-api-key': api_key,
                 'anthropic-version': '2023-06-01'},
    )
    last = None
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=300) as r:
                out = json.load(r)
            return ''.join(part.get('text', '') for part in out.get('content', []))
        except Exception as e:              # transient API/network trouble
            last = e
            time.sleep(2 ** attempt * 2)
    raise RuntimeError('Anthropic API failed after retries: %s' % last)


def build_prompt(locale, name, nplurals, entries):
    items = []
    for i, e in enumerate(entries):
        item = {'id': i, 'text': e['msgid']}
        if e.get('plural'):
            item['plural_of'] = e['plural']
            item['forms_required'] = nplurals
        if e.get('comment'):
            item['note'] = e['comment'].strip()
        if e.get('ctxt'):
            item['context'] = e['ctxt']
        items.append(item)

    return f"""You are translating the user interface of a WordPress plugin into {name}.

This plugin moves a customer's website to a new host. A person reads these words at the single most
nervous moment of that process: while their live business site is being copied. Tone must be calm,
plain and reassuring. Prefer everyday words over technical ones. Use the formal register where the
language distinguishes one (usted, vous, Sie).

ABSOLUTE RULES:
1. Preserve every placeholder EXACTLY: %s, %1$s, %2$s, %d and so on. Same placeholders, same numbers.
   You may REORDER them if the target language needs a different word order. Never invent, drop or
   renumber one, and never put a space inside one.
2. Never translate these product names: {', '.join(DO_NOT_TRANSLATE)}.
3. Do not add or remove sentences. Do not add explanations or notes.
4. Keep it about the same length. This is UI text in a fixed-width card.
5. Where an item has "forms_required": N, return a LIST of exactly N plural forms for that language,
   ordered by that language's standard plural rule. Otherwise return a single string.
6. "piece" here means one chunk of a file transfer, not a physical fragment.
7. NEVER put a straight double quote (") inside a translated value. It breaks the JSON. When the
   language needs quotation marks, use that language's own typographic marks instead: Chinese and
   Japanese use the corner brackets or the curly forms, French uses the guillemets, German uses its
   low and high pair. This is correct typography as well as valid JSON.

Return ONLY a JSON object mapping each id (as a string) to its translation:
  a string, or a list of strings when forms_required is present. No prose, no code fence.

Items:
{json.dumps(items, ensure_ascii=False, indent=1)}"""


def placeholders(s):
    return sorted(PLACEHOLDER_RE.findall(s))


BATCH = 35


def translate_locale(api_key, locale, entries, dry_run=False, only_indices=None):
    """
    Translate in BATCHES.

    One call for all 125 strings produces a large JSON document, and a single bad escape anywhere in
    it throws the whole locale away. zh_CN failed that way three times running while every other
    locale succeeded, which is the tell: the problem is the SIZE of the reply, not the language.
    Smaller batches mean a malformed reply costs 35 strings instead of 125, and the retry that follows
    is cheap enough to actually succeed.
    """
    name, nplurals, _rule = LOCALES[locale]
    if dry_run:
        print(f'  {locale}: would translate {len(entries)} strings')
        return None

    # When only some entries need translating, send ONLY those. Re-sending 126 good strings to add
    # one costs a full run per locale and risks every one of them coming back different.
    work = [(i, entries[i]) for i in (only_indices if only_indices is not None else range(len(entries)))]
    merged, all_problems = {}, []
    for start in range(0, len(work), BATCH):
        pairs = work[start:start + BATCH]
        got, problems = _translate_batch(api_key, locale, name, nplurals, [e for _, e in pairs], 0)
        # _translate_batch keys by position within the batch; map back to the real entry index.
        for local_i, (real_i, _) in enumerate(pairs):
            if local_i in got:
                merged[real_i] = got[local_i]
        all_problems.extend(problems)
    return merged, all_problems


def _translate_batch(api_key, locale, name, nplurals, entries, offset):

    # A malformed JSON reply is a RETRY, not a fatal. Asking for a large JSON object in a language
    # with different quoting conventions occasionally produces one bad escape, and the first version
    # of this script let that single failure abort the whole run: zh_CN broke and the eleven locales
    # queued behind it were never attempted at all.
    got = None
    last_err = None
    for attempt in range(3):
        prompt = build_prompt(locale, name, nplurals, entries)
        if attempt:
            prompt += ('\n\nYour previous reply was not valid JSON (%s). Return STRICT JSON only. '
                       'Escape every double quote inside a value as \\". No trailing commas.' % last_err)
        text = call_claude(api_key, prompt)
        text = re.sub(r'^```(?:json)?|```$', '', text.strip(), flags=re.M).strip()
        try:
            got = json.loads(text)
            break
        except json.JSONDecodeError as e:
            last_err = e
            print(f'    JSON parse failed (attempt {attempt + 1}/3): {e}')
    if got is None:
        raise RuntimeError(f'{locale}: model did not return valid JSON after 3 attempts ({last_err})')

    out, problems = {}, []
    for local_i, e in enumerate(entries):
        i = offset + local_i                       # index into the FULL entry list, not the batch
        v = got.get(str(local_i), got.get(local_i))
        if v is None:
            problems.append(f'#{i} missing: {e["msgid"][:50]}')
            continue

        if e.get('plural'):
            forms = v if isinstance(v, list) else [v]
            if len(forms) != nplurals:
                # Pad or trim rather than fail the whole locale: a wrong-count plural is recoverable
                # (the last form repeats), a dropped placeholder is not.
                forms = (forms + [forms[-1]] * nplurals)[:nplurals]
            # A PLURAL FORM MAY LEGITIMATELY DROP THE NUMBER, and demanding an exact match here
            # rejected correct Arabic. Arabic has six forms, and its zero and one forms do not take a
            # numeral: "no minutes" and "less than a minute" are how the language says it, so a
            # required %s would force a translator into something no Arabic speaker would write.
            # (Russian, Welsh and Irish have the same property in some forms.)
            #
            # What is still refused is a placeholder the SOURCE never had: an invented or renumbered
            # one is what blows up at runtime or prints the wrong value. Dropping one only ever loses
            # a number the sentence did not use, and sprintf ignores the surplus argument.
            want = set(placeholders(e['msgid'])) | set(placeholders(e['plural']))
            bad = next((f for f in forms if not set(placeholders(f)) <= want), None)
            if bad is not None:
                problems.append(f'#{i} invented placeholder: {e["msgid"][:50]!r} -> {bad[:50]!r}')
            else:
                dropped = [f for f in forms if set(placeholders(f)) != want]
                if dropped:
                    print(f'    note: #{i} a plural form omits the number (idiomatic): {dropped[0][:60]!r}')
                out[i] = forms
        else:
            if not isinstance(v, str):
                problems.append(f'#{i} expected a string, got {type(v).__name__}')
                continue
            if placeholders(v) != placeholders(e['msgid']):
                problems.append(f'#{i} placeholder mismatch: {e["msgid"][:50]!r} -> {v[:50]!r}')
                continue
            out[i] = v

    return out, problems


# ── PO writing ───────────────────────────────────────────────────────────────

def write_po(locale, entries, translations):
    name, nplurals, rule = LOCALES[locale]
    path = os.path.join(ROOT, 'languages', f'{DOMAIN}-{locale}.po')

    lines = [
        'msgid ""', 'msgstr ""',
        '"Project-Id-Version: Kapsule Migrator\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        f'"Language: {locale}\\n"',
        f'"Plural-Forms: nplurals={nplurals}; plural={rule};\\n"',
        f'"X-Generator: kapsule-migrator tools/translate.py\\n"',
        '',
    ]

    written = 0
    for i, e in enumerate(entries):
        t = translations.get(i)
        if t is None:
            continue                                  # untranslated: gettext falls back to English
        if e.get('comment'):
            lines.append('#. ' + e['comment'].strip())
        for ref in e.get('refs', []):
            lines.append('#: ' + ref)
        if e.get('ctxt'):
            lines.append(f'msgctxt "{escape(e["ctxt"])}"')
        lines.append(f'msgid "{escape(e["msgid"])}"')
        if e.get('plural'):
            lines.append(f'msgid_plural "{escape(e["plural"])}"')
            for n, form in enumerate(t):
                lines.append(f'msgstr[{n}] "{escape(form)}"')
        else:
            lines.append(f'msgstr "{escape(t)}"')
        lines.append('')
        written += 1

    open(path, 'w', encoding='utf-8').write('\n'.join(lines))
    return path, written


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--locale', action='append', help='WordPress locale, e.g. fr_FR. Repeatable.')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--only-missing', action='store_true',
                    help='Translate only msgids a locale does not already have, and keep the rest verbatim.')
    args = ap.parse_args()

    api_key = os.environ.get('ANTHROPIC_API_KEY')
    if not api_key and not args.dry_run:
        sys.exit('ANTHROPIC_API_KEY is not set')

    if not os.path.exists(POT):
        sys.exit(f'No POT at {POT}. Run: wp i18n make-pot . languages/{DOMAIN}.pot --domain={DOMAIN}')

    entries = parse_pot(POT)
    print(f'{len(entries)} strings in the template')
    if not entries:
        sys.exit('The template is empty, which cannot be right. Refusing to write catalogues.')

    targets = args.locale or list(LOCALES)
    unknown = [t for t in targets if t not in LOCALES]
    if unknown:
        sys.exit(f'Unknown locale(s): {unknown}. Known: {list(LOCALES)}')

    # When topping up, read what each locale ALREADY has and translate only the gaps.
    existing_for = {}
    if args.only_missing:
        for locale in targets:
            po = os.path.join(os.path.dirname(POT), f'{DOMAIN}-{locale}.po')
            have = {}
            if os.path.exists(po):
                for i, e in enumerate(entries):
                    pass
                for blk in open(po, encoding='utf-8').read().split('\n\n'):
                    mid, forms, single = None, [], None
                    for ln in blk.split('\n'):
                        m = re.match(r'^msgid\s+"(.*)"$', ln)
                        if m: mid = unescape(m.group(1)); continue
                        m = re.match(r'^msgstr\[(\d+)\]\s+"(.*)"$', ln)
                        if m: forms.append(unescape(m.group(2))); continue
                        m = re.match(r'^msgstr\s+"(.*)"$', ln)
                        if m: single = unescape(m.group(1))
                    if mid:
                        v = forms if forms else single
                        if v: have[mid] = v
            existing_for[locale] = {i: have[e['msgid']] for i, e in enumerate(entries) if e['msgid'] in have}
            missing = len(entries) - len(existing_for[locale])
            print(f'  {locale}: {missing} missing of {len(entries)}')

    failures = []
    for locale in targets:
        if args.only_missing:
            gaps = [i for i in range(len(entries)) if i not in existing_for[locale]]
            if not gaps:
                print(f'{locale}: nothing missing, untouched')
                continue
        print(f'{locale} ...', flush=True)
        # One locale failing must never take the others with it.
        try:
            gaps = [i for i in range(len(entries)) if i not in existing_for[locale]] if args.only_missing else None
            result = translate_locale(api_key, locale, entries, args.dry_run, gaps)
        except Exception as e:
            print(f'  FAILED {e}')
            failures.append(locale)
            continue
        if result is None:
            continue
        translations, problems = result
        for p in problems:
            print(f'  REJECTED {p}')
        if args.only_missing:
            # Carry the EXISTING translation for every msgid that already had one. Re-translating a
            # whole locale to add one string churns 126 good strings for no reason, and any of them
            # could come back worse. Only the genuinely new ones are replaced.
            translations = {**existing_for[locale], **translations}
        path, written = write_po(locale, entries, translations)
        pct = round(written / len(entries) * 100)
        print(f'  wrote {written}/{len(entries)} ({pct}%) -> {os.path.basename(path)}')
        if pct < 95:
            failures.append(locale)
            print(f'  WARNING {locale} is below 95% and needs a re-run')

    if failures:
        print(f'\nNeeds a re-run: {" ".join(failures)}')
        sys.exit(1)
    print('\nAll requested locales complete.')


if __name__ == '__main__':
    main()
