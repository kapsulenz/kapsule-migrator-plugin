#!/bin/bash
# build-zip.sh — packages kapsule-migrator plugin into a distributable zip
# Usage: bash build-zip.sh [output_dir] [--wporg]
#   Default:  CDN build — includes Update URI header (for kpanel.kapsulehost.com distribution)
#   --wporg:  WP.org build — strips Update URI header (WordPress.org update mechanism takes over)

set -euo pipefail

OUTPUT_DIR="$(pwd)"
WPORG=false

for arg in "$@"; do
    case "$arg" in
        --wporg) WPORG=true ;;
        *) OUTPUT_DIR="$arg" ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="/tmp/kapsule-migrator-build-$$"

if [ "$WPORG" = true ]; then
    ZIP_NAME="kapsule-migrator-wporg.zip"
else
    ZIP_NAME="kapsule-migrator.zip"
fi

ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

echo "[build-plugin-zip] Building ${ZIP_NAME}..."

mkdir -p "${TMP_DIR}/kapsule-migrator"
cp -r \
    "${SCRIPT_DIR}/kapsule-migrator.php" \
    "${SCRIPT_DIR}/includes" \
    "${SCRIPT_DIR}/admin" \
    "${SCRIPT_DIR}/assets" \
    "${SCRIPT_DIR}/readme.txt" \
    "${TMP_DIR}/kapsule-migrator/"

[ -d "${SCRIPT_DIR}/languages" ] && cp -r "${SCRIPT_DIR}/languages" "${TMP_DIR}/kapsule-migrator/"

if [ "$WPORG" = true ]; then
    # Strip Update URI header — WP.org manages its own update channel
    sed -i '/^ \* Update URI:/d' "${TMP_DIR}/kapsule-migrator/kapsule-migrator.php"
    echo "[build-plugin-zip] WP.org build: stripped Update URI header"
fi

rm -f "${ZIP_PATH}"
cd "${TMP_DIR}" && zip -r "${ZIP_PATH}" kapsule-migrator/ -x "*.DS_Store" -x "__MACOSX/*"

rm -rf "${TMP_DIR}"

echo "[build-plugin-zip] Done -> ${ZIP_PATH}"

# ── THE RELEASE CHECK RUNS HERE, BECAUSE NOTHING WAS RUNNING IT ────────────────────────────────────
#
# `tools/verify-release.sh` was written to catch exactly the failure that then happened twice, and on
# 2026-08-27 `grep -rn verify-release` over this whole repository returned ONE line: its own usage
# comment. Nothing called it. It was a correct, complete, landed report, and it was not on the path,
# so every publish went round it.
#
# A line in a document recommending it would have changed nothing, because the recipe people actually
# follow is this script. So it goes IN the script. The check is the last thing between a build and a
# customer, and this is the last place a build exists before it is copied into the portal.
#
# It is a WARNING here and not a hard failure, deliberately and narrowly: this script is also used to
# produce a scratch build for local testing, where the portal tree is not present and the endpoint
# version legitimately has not moved yet. A build that refuses to exist unless it is releasable is a
# build you cannot test. What must not be possible is producing a release build and never being told,
# and the summary below is printed loudly enough that it cannot be missed.
if [ "$WPORG" = false ] && [ -f "${SCRIPT_DIR}/tools/verify-release.sh" ]; then
    echo
    echo "[build-plugin-zip] running the release check on the artefact just built"
    if ( cd "${SCRIPT_DIR}" && bash tools/verify-release.sh ); then
        echo "[build-plugin-zip] release check PASSED"
    else
        echo
        echo "[build-plugin-zip] ############################################################"
        echo "[build-plugin-zip] # RELEASE CHECK FAILED. The zip above was built and is NOT #"
        echo "[build-plugin-zip] # safe to publish. Read the lines above before copying it   #"
        echo "[build-plugin-zip] # into the portal: at least one customer would never receive #"
        echo "[build-plugin-zip] # this build, or would receive a different one.              #"
        echo "[build-plugin-zip] ############################################################"
    fi
fi
