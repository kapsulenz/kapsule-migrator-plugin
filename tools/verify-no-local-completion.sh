#!/usr/bin/env bash
#
# THE PLUGIN MUST NOT BE ABLE TO SAY A MIGRATION FINISHED.
#
# THE DEFECT, from Jesse's real migration of oaohost.com on 2026-08-24. The plugin's admin screen said
# "Move complete. Your site is on KapsuleHost. 21,129 files, 472.5 MB, database Copied, took 4 min"
# while the panel said "Scanning site files, 10%", and the job then FAILED on the database import. The
# customer was told the exact thing that was about to fail had already succeeded.
#
# It was not a wording mistake. `ajax_upload_db_and_complete` wrote `kapsule_migration_status =
# 'complete'` the moment the UPLOAD finished, and the WP-Cron path wrote it even earlier: BEFORE it had
# POSTed `action=complete`, which is the request that dispatches the worker. Two writers, one word, and
# the word meant "I finished sending" while the screen it drove said "your site has moved".
#
# So the fix was structural, and this is what keeps it structural. A comment saying "do not write
# 'complete' here" is advice. A check that reads the shipped source and refuses is a mechanism.
#
# WHAT IT ASSERTS, all by CONTENT rather than by trusting a naming convention:
#
#   1. No PHP or JS writes a completion-flavoured value to the local status option.
#   2. The vocabulary of local statuses does not contain one.
#   3. The completion copy exists in exactly ONE method, and that method's first statement is the
#      gate that returns unless the PORTAL reported COMPLETED. Deleting the gate deletes the copy.
#   4. `job_says_complete()` tests for exactly one job status and nothing else. This is the check
#      that catches the plausible-looking widening ("also accept COMPLETED_WITH_ERRORS"), which is
#      the same lie in a smaller size.
#
# Every rule below is proved able to FAIL by tools/verify-no-local-completion.sh --self-test, which
# runs each pattern against a copy of the source with the defect reintroduced. A pattern that cannot
# match its target returns zero and reads exactly like safety.
#
# Usage: tools/verify-no-local-completion.sh [--self-test]
set -uo pipefail
cd "$(dirname "$0")/.."

PHP_SRC="admin/class-admin-page.php includes/class-kapsule-migrator.php"
JS_SRC="assets/js/migrator.js"

# EVERY PATTERN BELOW READS CODE, NEVER COMMENTS.
#
# The first run of this script failed on ITSELF three times: the docblock that explains what was
# removed contains the removed code verbatim ("it called `setStep('kstep-done')`"), and the docblock
# that explains the completion card quotes its own heading. A checker that reads its own documentation
# as content is a named class on this estate (the em-dash gate flagged its own warning comment; a
# confabulation guard read "these SHOULD have been created" as a claim they had been). The failure
# direction here is the dangerous one: it makes an honest build look guilty, so the next person edits
# the DOCUMENTATION out to get green, and the reason the rule exists goes with it.
#
# So the source is stripped of comment lines first, and every content assertion reads the stripped
# copy. Line-oriented is sufficient because both languages here put block-comment continuations on
# their own `*` line and neither file has a trailing comment on a line that also carries one of these
# patterns; the self-test at the bottom proves each rule still detects its defect in real code.
STRIP=$(mktemp -d)
trap 'rm -rf "$STRIP"' EXIT
strip_comments() {  # <file> -> path to a comment-free copy
  local out="$STRIP/$(echo "$1" | tr / _)"
  sed -E '/^[[:space:]]*(\*|\/\*|\*\/|\/\/)/d' "$1" > "$out"
  printf '%s' "$out"
}
PHP_ADMIN_C=$(strip_comments admin/class-admin-page.php)
PHP_MIG_C=$(strip_comments includes/class-kapsule-migrator.php)
PHP_C="$PHP_ADMIN_C $PHP_MIG_C"
JS_C=$(strip_comments assets/js/migrator.js)

fail=0
ok()  { printf '  %-52s %s\n' "$1" "ok"; }
bad() { fail=1; printf '  %-52s FAIL: %s\n' "$1" "$2"; }

# ── 1. Nobody writes a completion value to the local status option ───────────────────────────────
# Matches the option write with any of the words an author would reach for. Anchored to the option
# name so an unrelated string containing "complete" (there are several, and they are correct copy)
# cannot trip it.
hits=$(grep -nE "update_option\(\s*'kapsule_migration_status'\s*,\s*'(complete|completed|done|finished|success)'" $PHP_C 2>/dev/null)
if [ -n "$hits" ]; then
  bad "no local write of a completion status" "$(printf '%s' "$hits" | tr '\n' ' ')"
else
  ok "no local write of a completion status"
fi

# The same value routed through the setter would be refused at runtime, but catching it here names the
# line rather than leaving a customer with an error card and an error_log entry.
hits=$(grep -nE "set_status\(\s*'(complete|completed|done|finished|success)'" $PHP_C 2>/dev/null)
if [ -n "$hits" ]; then
  bad "no completion value passed to set_status()" "$(printf '%s' "$hits" | tr '\n' ' ')"
else
  ok "no completion value passed to set_status()"
fi

# ── 2. The vocabulary itself has no such member ──────────────────────────────────────────────────
vocab=$(sed -n "/const STATUSES = array(/,/);/p" "$PHP_ADMIN_C")
if [ -z "$vocab" ]; then
  bad "STATUSES vocabulary is readable" "could not find const STATUSES; this check is blind"
elif printf '%s' "$vocab" | grep -qE "'(complete|completed|done|finished|success)'"; then
  bad "STATUSES contains no completion state" "$(printf '%s' "$vocab" | grep -oE "'(complete|completed|done|finished|success)'" | tr '\n' ' ')"
else
  ok "STATUSES contains no completion state"
fi
printf '%s' "$vocab" | grep -q "'awaiting_import'" \
  && ok "STATUSES has the honest terminal state awaiting_import" \
  || bad "STATUSES has the honest terminal state awaiting_import" "awaiting_import missing"

# ── 3. The completion copy lives behind the gate, in one place ───────────────────────────────────
n=$(grep -c "Your site is on KapsuleHost" "$PHP_ADMIN_C")
[ "$n" = "1" ] \
  && ok "completion heading appears exactly once" \
  || bad "completion heading appears exactly once" "found $n occurrences; it must live only in render_complete_card()"

card=$(sed -n "/private function render_complete_card(/,/^    }$/p" "$PHP_ADMIN_C")
if [ -z "$card" ]; then
  bad "render_complete_card() is readable" "method not found; this check is blind"
else
  # The gate must be the FIRST statement. A gate anywhere else can be walked past by an early echo.
  # The first line of $card is the signature; the first statement is whatever non-blank line
  # follows it. Requiring the GATE to be that line is what stops it being moved below an echo.
  first=$(printf '%s\n' "$card" | tail -n +2 | grep -m1 -E '[^[:space:]]')
  printf '%s' "$first" | grep -q 'job_says_complete' \
    && ok "render_complete_card() opens with the job_says_complete gate" \
    || bad "render_complete_card() opens with the job_says_complete gate" "first statement is: $first"
  printf '%s' "$card" | grep -q 'return false;' \
    && ok "the gate returns without emitting" \
    || bad "the gate returns without emitting" "no early return found"
  # And the heading must be INSIDE that method, not merely once in the file.
  printf '%s' "$card" | grep -q "Your site is on KapsuleHost" \
    && ok "the completion heading is inside the gated method" \
    || bad "the completion heading is inside the gated method" "heading is somewhere else"
fi

# ── 4. The gate tests for exactly one job status ─────────────────────────────────────────────────
gate=$(sed -n "/private static function job_says_complete(/,/^    }$/p" "$PHP_ADMIN_C")
if [ -z "$gate" ]; then
  bad "job_says_complete() is readable" "method not found; this check is blind"
else
  printf '%s' "$gate" | grep -q "self::JOB_COMPLETE" \
    && ok "the gate compares against JOB_COMPLETE" \
    || bad "the gate compares against JOB_COMPLETE" "it compares against something else"
  # Any second accepted status is the widening this exists to stop.
  if printf '%s' "$gate" | grep -qE "COMPLETED_WITH_ERRORS|OPS_ESCALATED|\|\||in_array"; then
    bad "the gate accepts only one status" "it also accepts something else"
  else
    ok "the gate accepts only one status"
  fi
fi
grep -q "const JOB_COMPLETE = 'COMPLETED';" "$PHP_ADMIN_C" \
  && ok "JOB_COMPLETE is the portal's COMPLETED" \
  || bad "JOB_COMPLETE is the portal's COMPLETED" "constant missing or changed"

# ── 5. The browser does not tick the far side's step ─────────────────────────────────────────────
# `kstep-done` is labelled "KapsuleHost puts it together". The browser ticking it on upload success
# was the front-end half of the same claim.
if grep -qE "setStep\('kstep-done'\)" "$JS_C"; then
  bad "the browser never ticks kstep-done" "$(grep -nE "setStep\('kstep-done'\)" "$JS_C" | tr '\n' ' ')"
else
  ok "the browser never ticks kstep-done"
fi
if grep -qE "setMeter\(100" "$JS_C"; then
  bad "the browser never shows 100%" "$(grep -nE "setMeter\(100" "$JS_C" | tr '\n' ' ')"
else
  ok "the browser never shows 100%"
fi

# ── 6. There is a door to the truth at all ───────────────────────────────────────────────────────
# The token is marked USED by `action=complete`, so without this endpoint the plugin is structurally
# unable to learn anything after the upload, and no wording can be true.
grep -q "job-status?token=" "$PHP_ADMIN_C" \
  && ok "the plugin reads the job's state from the portal" \
  || bad "the plugin reads the job's state from the portal" "no job-status fetch found"

# ── Self-test: prove every pattern above can actually FAIL ───────────────────────────────────────
if [ "${1:-}" = "--self-test" ]; then
  echo
  echo "self-test: reintroducing each defect into a copy and confirming this script goes red"
  tmp=$(mktemp -d)
  trap 'rm -rf "$tmp"' EXIT
  cp -r . "$tmp/plugin" 2>/dev/null
  selfok=1
  try() {
    local label="$1"; shift
    ( cd "$tmp/plugin" && "$@" ) >/dev/null 2>&1
    if ( cd "$tmp/plugin" && bash tools/verify-no-local-completion.sh ) >/dev/null 2>&1; then
      printf '  %-52s SELF-TEST FAIL: still green with the defect present\n' "$label"
      selfok=0
    else
      printf '  %-52s red as expected\n' "$label"
    fi
    ( cd "$tmp/plugin" && git checkout -- . ) >/dev/null 2>&1
  }
  try "local 'complete' write"      sed -i "s/self::set_status( 'awaiting_import' );/update_option( 'kapsule_migration_status', 'complete' );/" admin/class-admin-page.php
  try "'complete' back in STATUSES" sed -i "s/'awaiting_import',       \/\/ upload done/'complete', 'awaiting_import',       \/\/ upload done/" admin/class-admin-page.php
  try "gate widened to a partial"   sed -i "s/\$job\['status'\] === self::JOB_COMPLETE;/\$job['status'] === self::JOB_COMPLETE || \$job['status'] === 'COMPLETED_WITH_ERRORS';/" admin/class-admin-page.php
  try "browser ticks kstep-done"    sed -i "s/setChip('transferring', __('Handing over to KapsuleHost'/setStep('kstep-done'); setChip('transferring', __('Handing over to KapsuleHost'/" assets/js/migrator.js
  echo
  [ "$selfok" = "1" ] || { echo "SELF-TEST FAILED: at least one rule cannot detect its own defect."; exit 1; }
  echo "self-test passed: every rule goes red on the defect it exists to catch"
fi

echo
if [ "$fail" -ne 0 ]; then
  echo "FAILED: this build can report a completion the job has not reached."
  exit 1
fi
echo "The plugin cannot claim a migration finished. Only the job can."
