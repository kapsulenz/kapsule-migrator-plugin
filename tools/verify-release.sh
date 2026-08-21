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
# Run this BEFORE copying the zip into the portal, and again after.
#
# Usage: tools/verify-release.sh [--live]
#   --live also asks the running endpoint what it currently announces.
set -uo pipefail
cd "$(dirname "$0")/.."

PORTAL=/var/www/kapsulecloud-portal
ROUTE="$PORTAL/src/app/api/migration/plugin-version/route.ts"
ZIP="$PORTAL/public/downloads/kapsule-migrator.zip"
ENDPOINT=https://kpanel.kapsulehost.com/api/migration/plugin-version

fail=0
say() { printf '  %-34s %s\n' "$1" "$2"; }
bad() { fail=1; printf '  %-34s FAIL: %s\n' "$1" "$2"; }

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
