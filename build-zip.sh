#!/bin/bash
# build-zip.sh — packages kapsule-migrator plugin into a distributable zip
# Usage: bash build-zip.sh [output_dir] [--wporg]
#   Default:  CDN build — includes Update URI header (for kpanel.kapsulecloud.com distribution)
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
