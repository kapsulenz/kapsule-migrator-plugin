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

# THE FOLDER INSIDE THE ZIP IS NOT THE SAME FACT AS THE TEXT DOMAIN, and it is the one that can
# orphan people.
#
# WordPress identifies an installed plugin by `folder/file.php`. Change the folder and every existing
# install stops seeing updates and the old copy stays on disk beside the new one.
#
#   WP.org  the folder MUST equal the assigned slug, `kapsulehost-migrator`. Nobody has it installed
#           from there yet, so there is nothing to orphan and this is free.
#   CDN     the folder STAYS `kapsule-migrator`, because real installs are already at that path and
#           renaming it would break their update channel to fix nothing a customer can see.
#
# The text domain moved to `kapsulehost-migrator` on BOTH, because the catalogues are named by domain
# and the domain must match the slug for translate.wordpress.org. Domain and folder are allowed to
# differ, and here they deliberately do on one of the two channels.
if [ "$WPORG" = true ]; then
    ZIP_NAME="kapsule-migrator-wporg.zip"
    PLUGIN_DIR_NAME="kapsulehost-migrator"
else
    ZIP_NAME="kapsule-migrator.zip"
    PLUGIN_DIR_NAME="kapsule-migrator"
fi

ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

echo "[build-plugin-zip] Building ${ZIP_NAME}..."

mkdir -p "${TMP_DIR}/${PLUGIN_DIR_NAME}"
cp -r \
    "${SCRIPT_DIR}/kapsule-migrator.php" \
    "${SCRIPT_DIR}/includes" \
    "${SCRIPT_DIR}/admin" \
    "${SCRIPT_DIR}/assets" \
    "${SCRIPT_DIR}/readme.txt" \
    "${TMP_DIR}/${PLUGIN_DIR_NAME}/"

# THE .sql IS A DEV REFERENCE AND MUST NOT SHIP.
#
# `includes/dump-preamble.sql` is written by tools/derive-dump-preamble.sh and read ONLY by
# tools/verify-dump-preamble.sh in CI. Nothing in the plugin loads it: the preamble the code actually
# uses is includes/class-dump-preamble.php. It shipped anyway because this copied the whole includes/
# directory, and WordPress.org flagged it in the 19 Sep review as a possible database dump.
#
# They were right to flag it even though it holds no data. A .sql under wp-content/plugins/ is served
# by the webserver to anyone who asks, so shipping one is a habit worth not having regardless of what
# this particular file contains.
rm -f "${TMP_DIR}/${PLUGIN_DIR_NAME}/includes/"*.sql

if [ "$WPORG" = true ]; then
    # Strip Update URI header — WP.org manages its own update channel
    sed -i '/^ \* Update URI:/d' "${TMP_DIR}/${PLUGIN_DIR_NAME}/kapsule-migrator.php"
    echo "[build-plugin-zip] WP.org build: stripped Update URI header"
    # TRANSLATIONS ARE NOT OURS TO SHIP ON WP.org. translate.wordpress.org generates and delivers a
    # catalogue per locale through the normal update system, so a bundled languages/ directory is at
    # best redundant and at worst a stale copy that overrides the community's. The CDN build KEEPS
    # them, because nothing generates catalogues on that channel.
    rm -rf "${TMP_DIR}/${PLUGIN_DIR_NAME}/languages"
    echo "[build-plugin-zip] WP.org build: removed bundled languages/ (translate.wordpress.org owns these)"
else
    [ -d "${SCRIPT_DIR}/languages" ] && cp -r "${SCRIPT_DIR}/languages" "${TMP_DIR}/${PLUGIN_DIR_NAME}/"
fi

rm -f "${ZIP_PATH}"
cd "${TMP_DIR}" && zip -r "${ZIP_PATH}" "${PLUGIN_DIR_NAME}/" -x "*.DS_Store" -x "__MACOSX/*"

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
