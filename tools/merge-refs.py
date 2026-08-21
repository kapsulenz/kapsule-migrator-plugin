#!/usr/bin/env python3
"""
Copy `#:` source references from the POT into every .po, matching on msgid.

WHY THIS IS A SEPARATE TOOL. Source references are not documentation: `wp i18n make-json` uses them,
and ONLY them, to decide which strings belong to which JavaScript file. A .po without them compiles
to .mo files that look complete and to ZERO JavaScript catalogues, so the static half of the screen
translates and every string the transfer states build at runtime stays English. That is the worst
shape available here, because the runtime strings are the ones a customer reads while their site is
mid-move and something has gone wrong.

The references are a property of the SOURCE, not of any translation, so re-running the whole
translation pipeline to recover them would spend fifteen API calls to reproduce text that is already
correct. This copies them across instead, and refuses if the result would not actually help.
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
POT = os.path.join(LANG, 'kapsule-migrator.pot')


def read_blocks(path):
    """Split a PO/POT file into its header and a list of entry blocks."""
    text = open(path, encoding='utf-8').read()
    parts = text.split('\n\n')
    return parts


def msgid_of(block):
    """The msgid of a block, including continuation lines, unescaped just enough to compare."""
    lines = block.split('\n')
    out, collecting = [], False
    for ln in lines:
        m = re.match(r'^msgid\s+"(.*)"$', ln)
        if m:
            out.append(m.group(1)); collecting = True; continue
        if collecting:
            m2 = re.match(r'^"(.*)"$', ln)
            if m2:
                out.append(m2.group(1)); continue
            break
    return ''.join(out) if out else None


def refs_of(block):
    return [ln for ln in block.split('\n') if ln.startswith('#: ')]


def main():
    if not os.path.exists(POT):
        sys.exit(f'No POT at {POT}')

    pot_refs = {}
    for block in read_blocks(POT):
        mid = msgid_of(block)
        if mid:
            r = refs_of(block)
            if r:
                pot_refs[mid] = r
    print(f'{len(pot_refs)} template entries carry source references')
    if not pot_refs:
        sys.exit('The template has no source references at all. Regenerate it with wp i18n make-pot.')

    total_files = 0
    for name in sorted(os.listdir(LANG)):
        if not name.endswith('.po'):
            continue
        path = os.path.join(LANG, name)
        blocks = read_blocks(path)
        added = 0

        for i, block in enumerate(blocks):
            mid = msgid_of(block)
            if not mid or mid not in pot_refs:
                continue
            # REPLACE, do not merely fill in. A reference left pointing at a renamed or moved source
            # file is worse than none: make-json still emits a catalogue, named for the old path, that
            # WordPress will never look up.
            lines = [ln for ln in block.split('\n') if not ln.startswith('#: ')]
            # References go immediately before msgctxt/msgid, after any comments.
            idx = next((n for n, ln in enumerate(lines)
                        if ln.startswith('msgctxt') or ln.startswith('msgid')), None)
            if idx is None:
                continue
            rebuilt = '\n'.join(lines[:idx] + pot_refs[mid] + lines[idx:])
            if rebuilt != block:
                blocks[i] = rebuilt
                added += 1

        if added:
            open(path, 'w', encoding='utf-8').write('\n\n'.join(blocks))
        js = sum(1 for b in blocks for r in refs_of(b) if '.js:' in r)
        print(f'  {name:34} +{added:3} refs, {js} JS-sourced entries')
        total_files += 1

    print(f'\n{total_files} catalogues updated')


if __name__ == '__main__':
    main()
