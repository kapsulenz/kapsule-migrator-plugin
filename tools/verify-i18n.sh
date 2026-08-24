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
  # So the check is now the ORDER of the two files, which is the cheap fact that distinguishes
  # "compiled" from "present": a .mo older than its .po is stale by definition. This is the existence
  # versus fidelity distinction, and the fidelity half is the one that reaches a customer.
  po="$LANG_DIR/$DOMAIN-$loc.po"
  if [ -f "$mo" ] && [ -f "$po" ] && [ "$po" -nt "$mo" ]; then
    problems="$problems STALE-MO(po is newer, run: wp i18n make-mo languages/ languages/)"
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
