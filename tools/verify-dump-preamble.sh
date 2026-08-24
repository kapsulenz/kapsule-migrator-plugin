#!/usr/bin/env bash
#
# REFUSE IF THE COMMITTED PREAMBLE IS NOT WHAT A REAL mysqldump EMITS.
#
# The generated artefacts are committed so the plugin does not need a MySQL server to build a dump. A
# committed copy of something derived elsewhere is a copy that drifts, and this is what stops it: it
# re-runs the derivation and compares. Any difference fails.
#
# PROVE THIS CHECK CAN GO RED before trusting a green: `--self-test` corrupts the committed file in a
# temporary copy, runs the comparison against it, and requires a failure. A verifier that cannot fail
# is indistinguishable from one that passes, and this estate has shipped both.
#
# usage:  tools/verify-dump-preamble.sh [--via-ssh <host>] [--self-test]

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARGS=()
SELF_TEST=0
for a in "$@"; do
  if [ "$a" = "--self-test" ]; then SELF_TEST=1; else ARGS+=("$a"); fi
done

COMMITTED="$REPO/includes/dump-preamble.sql"
COMMITTED_PHP="$REPO/includes/class-dump-preamble.php"

for f in "$COMMITTED" "$COMMITTED_PHP"; do
  [ -s "$f" ] || { echo "FAIL: $f is missing or empty. Run tools/derive-dump-preamble.sh." >&2; exit 1; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp "$COMMITTED" "$TMP/before.sql"
cp "$COMMITTED_PHP" "$TMP/before.php"

restore() { cp "$TMP/before.sql" "$COMMITTED"; cp "$TMP/before.php" "$COMMITTED_PHP"; }

# Re-derive in place, compare, then put the committed files back exactly as they were whatever happened.
set +e
"$REPO/tools/derive-dump-preamble.sh" ${ARGS[@]+"${ARGS[@]}"} >/dev/null 2>"$TMP/derive.err"
DERIVE_RC=$?
set -e
if [ "$DERIVE_RC" -ne 0 ]; then
  restore
  echo "FAIL: could not re-derive the preamble, so this check could not run." >&2
  tail -5 "$TMP/derive.err" >&2
  exit 1
fi
cp "$COMMITTED" "$TMP/after.sql"
cp "$COMMITTED_PHP" "$TMP/after.php"
restore

compare() {
  # $1 = the .sql to test against the freshly derived one
  diff -u "$TMP/after.sql" "$1" >"$TMP/diff.txt" 2>&1
}

if [ "$SELF_TEST" = "1" ]; then
  # A control that must go RED: drop the TIME_ZONE line, which is the setting whose absence shifts
  # every timestamp on a migrated site, and confirm the comparison notices.
  grep -v "SET TIME_ZONE='+00:00'" "$TMP/before.sql" > "$TMP/corrupt.sql"
  if compare "$TMP/corrupt.sql"; then
    echo "FAIL: the self-test did NOT go red against a preamble with TIME_ZONE removed." >&2
    echo "      This check cannot detect drift and its green means nothing." >&2
    exit 1
  fi
  echo "self-test: comparison went RED against a corrupted preamble, as required."
fi

if ! compare "$TMP/before.sql"; then
  echo "FAIL: includes/dump-preamble.sql does not match what mysqldump emits today." >&2
  cat "$TMP/diff.txt" >&2
  echo "Run tools/derive-dump-preamble.sh and commit the result." >&2
  exit 1
fi
if ! diff -q "$TMP/after.php" "$TMP/before.php" >/dev/null; then
  echo "FAIL: includes/class-dump-preamble.php is not what the derivation produces." >&2
  diff -u "$TMP/after.php" "$TMP/before.php" >&2 || true
  exit 1
fi

echo "OK: the committed preamble matches a real mysqldump ($(grep -c '^/\*!' "$COMMITTED") session lines)."
