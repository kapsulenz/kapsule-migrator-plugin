#!/usr/bin/env bash
#
# Assert the translation catalogues are ones WordPress will actually LOAD.
#
# Every failure this guards against is silent. The plugin renders, nothing errors, and a customer
# simply reads English. Three separate near-misses on this build, each of which looked complete:
#
#   1. Strings not wrapped at all, while the text-domain header and load_plugin_textdomain() were
#      both present, so every automated i18n check passed.
#   2. A POT with zero entries from the JavaScript, because the text domain was passed as a variable
#      the extractor cannot resolve. "Success: POT generated" either way.
#   3. JS catalogues named for the WRONG PATH HASH. `wp i18n make-json` strips a `?min.js` suffix to
#      map minified files back to source, loosely enough to eat a real letter: admin.js became a.js.
#      All 15 .json files existed and were correct inside; WordPress looked up a different filename
#      and found nothing.
#
# So this checks the thing that actually matters, which is not "do the files exist" but "does the
# name WordPress computes match the name on disk".
#
# Usage: tools/verify-i18n.sh
set -uo pipefail
cd "$(dirname "$0")/.."

DOMAIN=kapsule-migrator
LANG_DIR=languages
JS_REL="assets/js/migrator.js"          # path relative to the plugin root, which is what WP hashes
EXPECTED_LOCALES=(en_NZ mi ar zh_CN de_DE fr_FR es_ES it_IT nl_NL pt_PT ja ko_KR hi_IN tr_TR ru_RU)

fail=0
note() { printf '  %-10s %s\n' "$1" "$2"; }

if [ ! -f "$JS_REL" ]; then
  echo "FAIL: $JS_REL does not exist; the enqueue and this check disagree about the script path"
  exit 1
fi

WANT_HASH=$(printf '%s' "$JS_REL" | md5sum | cut -d' ' -f1)
echo "WordPress will look for the JS catalogue at md5($JS_REL) = $WANT_HASH"
echo

# The path in the enqueue must be the path hashed here, or this check proves nothing.
if ! grep -q "assets/js/$(basename "$JS_REL")" admin/class-admin-page.php; then
  echo "FAIL: admin/class-admin-page.php does not enqueue $JS_REL"
  fail=1
fi

for loc in "${EXPECTED_LOCALES[@]}"; do
  mo="$LANG_DIR/$DOMAIN-$loc.mo"
  js="$LANG_DIR/$DOMAIN-$loc-$WANT_HASH.json"
  problems=""

  [ -f "$mo" ] || problems="$problems no-mo"

  # A .mo THAT EXISTS IS NOT A .mo THAT CARRIES TODAY'S COPY, and existence is all this used to check.
  #
  # Caught for real on 2026-08-24: a new customer-facing string was written, translated into all
  # fifteen locales, and every .po was updated. WordPress does not read .po. It reads .mo, which is
  # COMPILED from the .po by `wp i18n make-mo` as a separate step, and that step had not been run. Every
  # locale reported "ok" here, the release check reported "15 .mo files", the zip was built, and the
  # only thing that would have told anyone was a customer reading an English sentence in Japanese.
  #
  # THE FIRST FIX FOR THAT COMPARED MTIMES, AND MTIME IS NOT THE FACT WE CARE ABOUT.
  #
  # `[ "$po" -nt "$mo" ]` compares modification times at NANOSECOND resolution, so it does not
  # measure staleness, it measures the order two files happened to be written in. Measured on this
  # repo 2026-08-27: five locales reported STALE-MO with their .po exactly ONE MILLISECOND newer
  # than their .mo (ja 1787682992.882203429 against .881203426), which is a checkout writing files
  # in sequence and nothing else. Five false alarms out of fifteen, on a pristine tree, permanently.
  #
  # It fails in the alarming direction, which is the cheap one, and that is exactly what makes it
  # dangerous over time: a gate that cries wolf on a clean checkout is a gate people learn to skip,
  # and then the real staleness it was written for goes through with it.
  #
  # SO IT COUNTS STRINGS INSTEAD, which is the fact the original defect was actually about. A .mo is
  # a binary catalogue whose header records how many strings it holds, at a fixed offset, and that
  # number IS what `msgfmt` emitted. Compare it against the number of TRANSLATED entries in the .po
  # (a msgid with a non-empty msgstr; msgfmt omits untranslated ones, so counting every msgid would
  # produce a permanent off-by-N in the other direction). A .po that gained a translated string and
  # was never recompiled comes out short here, which is the 2026-08-24 defect exactly, and it is
  # immune to file order, to a checkout, to a copy and to a touch.
  #
  # `msgfmt` is deliberately not used even where it exists: recompiling to compare would make this
  # gate depend on a tool that is absent on this box, and BLIND is the answer it would then have to
  # give on the one question it exists to answer.
  po="$LANG_DIR/$DOMAIN-$loc.po"
  if [ -f "$mo" ] && [ -f "$po" ]; then
    counts=$(python3 - "$po" "$mo" <<'PYEOF' 2>/dev/null
import re, struct, sys

po_path, mo_path = sys.argv[1], sys.argv[2]

# Count msgid/msgstr pairs whose msgstr is non-empty. The catalogue header is the entry whose
# msgid is the empty string, and msgfmt DOES emit it, so it is counted here too.
text = open(po_path, encoding='utf-8', errors='replace').read()
entries, cur, key = 0, {}, None
for line in text.splitlines():
    line = line.strip()
    if line.startswith('msgid '):
        if key and cur.get('msgstr'):
            entries += 1
        cur, key = {}, 'msgid'
        cur[key] = line[6:].strip()
    elif line.startswith('msgid_plural '):
        key = 'msgid'
    elif line.startswith('msgstr'):
        key = 'msgstr'
        cur[key] = cur.get('msgstr', '') + line.split(' ', 1)[-1].strip().strip('"')
    elif line.startswith('"') and key:
        cur[key] = cur.get(key, '') + line.strip('"')
if key and cur.get('msgstr'):
    entries += 1

# .mo header: magic, revision, then the string count at byte offset 8.
raw = open(mo_path, 'rb').read()
magic = raw[:4]
endian = '<' if magic == b'\xde\x12\x04\x95' else '>' if magic == b'\x95\x04\x12\xde' else None
if endian is None:
    print('BADMAGIC'); sys.exit(0)
print('%d %d' % (struct.unpack(endian + 'I', raw[8:12])[0], entries))
PYEOF
    )
    mo_n=${counts%% *}
    po_n=${counts##* }
    if [ "$counts" = "BADMAGIC" ]; then
      problems="$problems MO-NOT-A-CATALOGUE"
    elif [ -n "$mo_n" ] && [ -n "$po_n" ] && [ "$mo_n" -lt "$po_n" ] 2>/dev/null; then
      problems="$problems STALE-MO(mo holds $mo_n strings, po has $po_n translated, run: wp i18n make-mo languages/ languages/)"
    fi
  fi

  if [ -f "$js" ]; then
    n=$(python3 -c "import json,sys;d=json.load(open('$js'));print(len(d['locale_data']['messages'])-1)" 2>/dev/null || echo 0)
    [ "$n" -gt 30 ] || problems="$problems js-only-$n-strings"
  else
    # Name and shame the wrong-hash case specifically: "missing" and "present under a name WordPress
    # will never ask for" need completely different fixes.
    stray=$(ls "$LANG_DIR/$DOMAIN-$loc-"*.json 2>/dev/null | head -1)
    if [ -n "$stray" ]; then
      src=$(python3 -c "import json;print(json.load(open('$stray')).get('source','?'))" 2>/dev/null)
      problems="$problems WRONG-HASH(built-for:$src)"
    else
      problems="$problems no-js"
    fi
  fi

  if [ -n "$problems" ]; then
    note "$loc" "FAIL:$problems"
    fail=1
  else
    note "$loc" "ok (mo + js catalogue WordPress can find)"
  fi
done

echo
if [ "$fail" -ne 0 ]; then
  echo "i18n verification FAILED"
  exit 1
fi
echo "i18n verification passed for ${#EXPECTED_LOCALES[@]} locales"
