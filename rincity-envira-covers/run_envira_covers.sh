#!/usr/bin/env bash
set -euo pipefail

WP_BIN="${HOME}/wp-lsphp"
WP_PATH="/usr/local/lsws/wordpress"
SCRIPT="/home/morgan/rin_envira_covers/rin_envira_covers.php"
LOG="/home/morgan/rin_envira_covers/cron.log"
OUT_DIR="${RIN_OUT:-${WP_PATH}/wp-content/uploads/Rin_Covers}"
MANIFEST_PATH="${OUT_DIR}/manifest.json"

TMP_LOG="$(mktemp /tmp/rin-envira-covers.XXXXXX)"
TMP_MANIFEST_BEFORE="$(mktemp /tmp/rin-envira-manifest-before.XXXXXX)"
TMP_MANIFEST_AFTER="$(mktemp /tmp/rin-envira-manifest-after.XXXXXX)"

capture_manifest() {
  local src="$1"
  local dest="$2"
  if [[ -f "${src}" ]]; then
    cp -f "${src}" "${dest}"
  else
    : > "${dest}"
  fi
}

capture_manifest "${MANIFEST_PATH}" "${TMP_MANIFEST_BEFORE}"

set +e
"${WP_BIN}" --path="${WP_PATH}" eval-file "${SCRIPT}" > "${TMP_LOG}" 2>&1
status=$?
set -e

capture_manifest "${MANIFEST_PATH}" "${TMP_MANIFEST_AFTER}"

# Always persist output to the cron log, but only emit to stdout/stderr when
# the manifest content changed or the command failed. This avoids cron sending
# email for no-op runs.
cat "${TMP_LOG}" >> "${LOG}"

if [[ ${status} -ne 0 ]] || ! cmp -s "${TMP_MANIFEST_BEFORE}" "${TMP_MANIFEST_AFTER}"; then
  cat "${TMP_LOG}"
fi

rm -f "${TMP_LOG}"
rm -f "${TMP_MANIFEST_BEFORE}" "${TMP_MANIFEST_AFTER}"
exit "${status}"
