#!/usr/bin/env python3
"""
Assert every compiled catalogue actually contains translations, by reading the .mo binaries.

WHY NOT ASK WORDPRESS. The obvious check is a loop that calls switch_to_locale() and __() for each
locale in one PHP request. That was tried and it LIED: WordPress caches a loaded textdomain, so every
iteration after the first returned the previously loaded language, and the run reported 14 of 15
locales broken while printing the FRENCH string under the en_NZ heading. The catalogues were fine.
An instrument that cannot see what it claims to measure is worse than no instrument, because its
output looks like a finding.

So this reads the artefact. A .mo file is a documented binary format, the same bytes WordPress reads,
and parsing it here answers the only question that matters: does the compiled catalogue carry a real
translation for the strings a frightened customer has to understand.

The WordPress-side loading path is proven separately, by rendering the real plugin in fr_FR and ar
and reading the words off the screen.
"""
import glob, os, struct, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')

# Strings whose failure to translate actually costs the customer something.
PROBES = [
    'Your site is on KapsuleHost',
    'We have paused the move',
    '<strong>This site has not been changed.</strong>',   # the msgid carries its own emphasis markup
    'The connection dropped',
]

EXPECTED = ['en_NZ', 'mi', 'ar', 'zh_CN', 'de_DE', 'fr_FR', 'es_ES', 'it_IT',
            'nl_NL', 'pt_PT', 'ja', 'ko_KR', 'hi_IN', 'tr_TR', 'ru_RU']


def read_mo(path):
    """Parse a .mo into {msgid: msgstr}. Plurals keep only the first form, which is all we probe."""
    data = open(path, 'rb').read()
    magic = struct.unpack('<I', data[:4])[0]
    if magic == 0x950412de:
        endian = '<'
    elif magic == 0xde120495:
        endian = '>'
    else:
        raise ValueError(f'{path}: not a .mo file (magic {magic:#x})')

    count, orig_off, trans_off = struct.unpack(endian + 'III', data[8:20])
    out = {}
    for i in range(count):
        o_len, o_pos = struct.unpack(endian + 'II', data[orig_off + i * 8: orig_off + i * 8 + 8])
        t_len, t_pos = struct.unpack(endian + 'II', data[trans_off + i * 8: trans_off + i * 8 + 8])
        key = data[o_pos:o_pos + o_len].decode('utf-8', 'replace')
        val = data[t_pos:t_pos + t_len].decode('utf-8', 'replace')
        # context and plurals are NUL-separated; probe against the base form
        key = key.split('\x00')[0].split('\x04')[-1]
        val = val.split('\x00')[0]
        out[key] = val
    return out


def main():
    fail = 0
    seen = []
    for path in sorted(glob.glob(os.path.join(LANG, '*.mo'))):
        loc = os.path.basename(path)[len('kapsule-migrator-'):-3]
        seen.append(loc)
        try:
            cat = read_mo(path)
        except Exception as e:
            print(f'  {loc:7} FAIL unreadable: {e}')
            fail += 1
            continue

        missing, untranslated = [], []
        for p in PROBES:
            hit = next((v for k, v in cat.items() if k.startswith(p)), None)
            if hit is None:
                missing.append(p)
            elif not hit.strip():
                untranslated.append(p)
            # en_NZ legitimately matches English for many strings, so identity is not a failure there
            elif hit == p and loc != 'en_NZ':
                untranslated.append(p)

        sample = next((v for k, v in cat.items() if k.startswith('We have paused the move')), '')
        if missing or untranslated:
            print(f'  {loc:7} FAIL  missing={len(missing)} untranslated={len(untranslated)}')
            fail += 1
        else:
            print(f'  {loc:7} ok    {len(cat):3} strings | {sample[:46]}')

    for want in EXPECTED:
        if want not in seen:
            print(f'  {want:7} FAIL  no catalogue on disk')
            fail += 1

    print()
    if fail:
        print(f'{fail} catalogue(s) FAILED')
        return 1
    print(f'All {len(EXPECTED)} catalogues carry real translations for the customer-critical strings')
    return 0


if __name__ == '__main__':
    sys.exit(main())
