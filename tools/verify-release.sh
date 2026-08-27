#!/usr/bin/env bash
#
# Assert that publishing this plugin would actually reach a customer.
#
# A RELEASE IS THREE ARTEFACTS THAT MUST AGREE, and only one of them lives in this repo:
#
#   1. the plugin's own version   (kapsule-migrator.php header + KAPSULE_MIGRATOR_VERSION)
#   2. the zip                    (portal repo: public/downloads/kapsule-migrator.zip)
#   3. the version the update endpoint ANNOUNCES
#                                 (portal repo: src/app/api/migration/plugin-version/route.ts)
#
# Get any pair out of step and the failure is silent in a different direction each time:
#
#   - New zip, unchanged endpoint version: every existing install keeps its old copy forever,
#     because WordPress only offers an update when the ANNOUNCED version is greater than the
#     installed one. The fix shipped and nobody received it.
#   - New endpoint version, unchanged zip: WordPress offers an update and installs the OLD plugin,
#     so the customer is told they upgraded and did not.
#   - readme Stable tag out of step: this repo already drifted that way, sitting at 1.0.7 while the
#     header said 1.1.0.
#
# Run this BEFORE copying the zip into the portal, and again after. KM_PORTAL points it at the
# worktree the change is being made in; a lane never edits the deploy tree, so the artefacts are
# checked where they are authored and the train carries them from there.
#
# Usage: tools/verify-release.sh [--live]
#   --live also asks the running endpoint what it currently announces.
set -uo pipefail
cd "$(dirname "$0")/.."

# THIS GATE COULD NOT NAME THE TREE IT WAS GRADING, AND ITS DEFAULT WAS THE WRONG ONE.
#
# The default was `/home/jesse/hd-mailmigrate`, a LANE WORKTREE. Not the deploy tree, not the lane
# actually running the check, and not anything that serves a customer. It happened to sit at 1.4.0,
# so on 2026-08-27 this file reported "portal endpoint constant announces 1.4.0" against a tree
# holding 1.4.1 and a lane holding 1.5.0. Three versions, none of them wrong, and the output named
# none of the trees they came from. (`hd-mailmigrate` is separately the worktree CLAUDE.md flags for
# naming the PRODUCTION database and carrying live processes, so it is the worst available default.)
#
# A wrong ANSWER gets caught. A right answer about the WRONG SUBJECT does not, and this one had just
# been wired into build-zip.sh, where it would have graded a stale worktree on every release forever.
#
# So: the default is the DEPLOY TREE, which is the thing that actually serves, and the resolved paths
# are PRINTED. A gate that cannot name what it measured is not a gate.
PORTAL=${KM_PORTAL:-/var/www/kapsulecloud-portal}
ROUTE="$PORTAL/src/app/api/migration/plugin-version/route.ts"
ZIP="$PORTAL/public/downloads/kapsule-migrator.zip"
ENDPOINT=https://kpanel.kapsulehost.com/api/migration/plugin-version

echo "  subject: plugin=$(pwd)"
echo "           portal=$PORTAL$([ -n "${KM_PORTAL:-}" ] && echo '  (KM_PORTAL)' || echo '  (default: the deploy tree)')"
if [ ! -f "$ROUTE" ]; then
  echo "  REFUSING: no plugin-version route under $PORTAL. Point KM_PORTAL at the portal tree you mean."
  echo "  This is BLIND, not a pass."
  exit 2
fi
echo

fail=0
say() { printf '  %-34s %s\n' "$1" "$2"; }
bad() { fail=1; printf '  %-34s FAIL: %s\n' "$1" "$2"; }

# A RELEASE THAT COULD LIE ABOUT COMPLETION IS NOT RELEASABLE, whatever its version numbers say. This
# gate PRECEDES the artefact checks on purpose: the point of a release check is to stop a build
# reaching a customer, and consistent version numbers on a build that reports a migration finished
# before it has are three artefacts agreeing on the wrong thing.
if ! bash tools/verify-no-local-completion.sh >/dev/null 2>&1; then
  echo "RELEASE BLOCKED: this build can report a completion the job has not reached."
  echo "Run tools/verify-no-local-completion.sh for the failing rules."
  exit 1
fi
say "completion-truth gate" "passed"

HEADER_V=$(grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' kapsule-migrator.php | head -1)
CONST_V=$(grep -oP "KAPSULE_MIGRATOR_VERSION',\s*'\K[0-9.]+" kapsule-migrator.php | head -1)
README_V=$(grep -oP '^Stable tag:\s*\K[0-9.]+' readme.txt | head -1)

say "plugin header" "$HEADER_V"
[ "$CONST_V"  = "$HEADER_V" ] && say "KAPSULE_MIGRATOR_VERSION" "$CONST_V"  || bad "KAPSULE_MIGRATOR_VERSION" "$CONST_V does not match the header $HEADER_V"
[ "$README_V" = "$HEADER_V" ] && say "readme Stable tag" "$README_V" || bad "readme Stable tag" "$README_V does not match the header $HEADER_V"

# The changelog must actually mention the version being shipped, or the customer-facing "what
# changed" is a lie of omission on the one screen where they look for it.
grep -q "^= ${HEADER_V} =" readme.txt && say "changelog entry" "present for $HEADER_V" \
  || bad "changelog entry" "readme.txt has no '= $HEADER_V =' section"

if [ -f "$ROUTE" ]; then
  ROUTE_V=$(grep -oP "PLUGIN_VERSION\s*=\s*'\K[0-9.]+" "$ROUTE" | head -1)
  if [ "$ROUTE_V" = "$HEADER_V" ]; then
    say "portal endpoint constant" "$ROUTE_V"
  else
    bad "portal endpoint constant" "announces $ROUTE_V, plugin is $HEADER_V (customers would never be offered this build)"
  fi
else
  bad "portal endpoint constant" "route not found at $ROUTE"
fi

# The published zip must be the build that matches, checked by reading the version OUT of it rather
# than trusting its timestamp.
if [ -f "$ZIP" ]; then
  ZIP_V=$(unzip -p "$ZIP" kapsule-migrator/kapsule-migrator.php 2>/dev/null | grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' | head -1)
  if [ "$ZIP_V" = "$HEADER_V" ]; then
    say "published zip" "contains $ZIP_V"
  else
    bad "published zip" "contains ${ZIP_V:-nothing readable}, plugin is $HEADER_V"
  fi
  # And it must carry the catalogues, or it ships English to everyone regardless of the .po files here.
  n=$(unzip -l "$ZIP" 2>/dev/null | grep -c 'languages/.*\.mo')
  [ "${n:-0}" -ge 15 ] && say "catalogues in the zip" "$n .mo files" || bad "catalogues in the zip" "only ${n:-0} .mo files, expected 15"

  # ── TWO DIFFERENT BUILDS UNDER ONE VERSION ────────────────────────────────────────────────────
  #
  # THE DEFECT THIS EXISTS TO CATCH, and every check above is blind to it BY CONSTRUCTION.
  #
  # 1.4.1 was published twice, on 2026-08-25 and again on 2026-08-26, with different contents. The
  # second carried the fix removing the white underlines from the plugin's buttons. Everything above
  # compares version strings TO EACH OTHER, and all of them said 1.4.1 on both days, so this file
  # would have passed both publishes while reporting "Release artefacts agree on 1.4.1". It did agree.
  # It agreed on a version that named two different builds.
  #
  # WHAT IT COST A CUSTOMER, exactly. WordPress offers an update only when the ANNOUNCED version is
  # greater than the installed one, so anyone who installed on the 25th was never offered the 26th and
  # keeps the underlines forever. Worse for everyone else: WordPress enqueues the stylesheet as
  # `admin.css?ver=KAPSULE_MIGRATOR_VERSION`, so a browser that cached `?ver=1.4.1` on the 25th serves
  # that copy against the new plugin indefinitely. Jesse reported the underlines as fixed and back
  # twice. The fix was in the source AND in the zip both times; it could not reach him.
  #
  # SO THE VERSION IS A CACHE KEY AND A DELIVERY KEY, NOT A LABEL. Changing what is inside it without
  # changing it is not a small release, it is an undeliverable one.
  #
  # The ledger is a plain file of `version sha256` lines, committed. Comparing against the LAST
  # PUBLISHED hash rather than against a rebuild is deliberate: rebuilding the same tree does not
  # produce a byte-identical zip (timestamps and compression vary), so a rebuild check would fire on
  # every honest run and be turned off within a week.
  LEDGER="published-versions.txt"
  ZIP_SHA=$(sha256sum "$ZIP" 2>/dev/null | cut -d' ' -f1)
  if [ -n "$ZIP_SHA" ]; then
    PREV_SHA=$(grep -E "^${HEADER_V//./\\.} " "$LEDGER" 2>/dev/null | tail -1 | awk '{print $2}')
    if [ -z "$PREV_SHA" ]; then
      say "version/content ledger" "$HEADER_V not published before, nothing to contradict"
    elif [ "$PREV_SHA" = "$ZIP_SHA" ]; then
      say "version/content ledger" "$HEADER_V unchanged since it was published"
    else
      bad "version/content ledger" \
        "$HEADER_V was ALREADY PUBLISHED with different contents (was ${PREV_SHA:0:12}, now ${ZIP_SHA:0:12}). Every install that already has $HEADER_V will never be offered this build, and cached CSS and JS will not reload. BUMP THE VERSION."
    fi
  fi
else
  say "published zip" "not yet copied into the portal (expected before release)"
fi

if [ "${1:-}" = "--live" ]; then
  LIVE_V=$(curl -fsS --max-time 20 "$ENDPOINT" 2>/dev/null | grep -oP '"version"\s*:\s*"\K[0-9.]+')
  if [ "$LIVE_V" = "$HEADER_V" ]; then
    say "live endpoint" "announces $LIVE_V"
  else
    bad "live endpoint" "announces ${LIVE_V:-unreachable}, plugin is $HEADER_V (not deployed yet)"
  fi
fi

echo
if [ "$fail" -ne 0 ]; then
  echo "RELEASE NOT CONSISTENT. Publishing now would ship a version some customers can never receive."
  exit 1
fi
echo "Release artefacts agree on $HEADER_V"
