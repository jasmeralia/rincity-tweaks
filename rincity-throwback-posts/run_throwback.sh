#!/usr/bin/env bash
set -euo pipefail

VENV_PY="/home/morgan/rincity-tweaks/rincity-throwback-posts/.venv/bin/python"
SCRIPT="/home/morgan/rincity-tweaks/rincity-throwback-posts/rin_throwback_post.py"
WORKDIR="/home/morgan/rincity-tweaks/rincity-throwback-posts"
MANIFEST="/usr/local/lsws/wordpress/wp-content/uploads/Rin_Covers/manifest.json"
IMAGES_DIR="/usr/local/lsws/wordpress/wp-content/uploads/Rin_Covers"
HISTORY="/home/morgan/rincity-tweaks/rincity-throwback-posts/post_history.json"
LOG="/home/morgan/rincity-tweaks/rincity-throwback-posts/cron.log"
# THRESHOLD_DAYS="3964"
THRESHOLD_DAYS="300"
RECORD_DRY_RUN="false"
DRY_RUN="false"
PLATFORM="both"
SET_NAME=""

usage() {
  cat <<'EOF'
Usage: run_throwback.sh [--platform twitter|bluesky|both] [--set-name "Name"] [--dry-run] [--record-dry-run]
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --platform)
      if [[ $# -lt 2 ]]; then
        echo "ERROR: --platform requires a value" >&2
        usage
        exit 2
      fi
      PLATFORM="$2"
      shift 2
      ;;
    --set-name)
      if [[ $# -lt 2 ]]; then
        echo "ERROR: --set-name requires a value" >&2
        usage
        exit 2
      fi
      SET_NAME="$2"
      shift 2
      ;;
    --dry-run)
      DRY_RUN="true"
      shift
      ;;
    --record-dry-run)
      RECORD_DRY_RUN="true"
      DRY_RUN="true"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "ERROR: Unknown argument: $1" >&2
      usage
      exit 2
      ;;
  esac
done

EXTRA_ARGS=()
if [[ "${DRY_RUN}" == "true" || "${RECORD_DRY_RUN}" == "true" ]]; then
  EXTRA_ARGS+=(--dry-run)
fi
if [[ "${RECORD_DRY_RUN}" == "true" ]]; then
  EXTRA_ARGS+=(--record-dry-run)
fi
if [[ -n "${SET_NAME}" ]]; then
  EXTRA_ARGS+=(--set-name "${SET_NAME}")
fi

TMP_LOG="$(mktemp /tmp/rin-throwback-post.XXXXXX)"

set +e
cd "${WORKDIR}"
"${VENV_PY}" "${SCRIPT}" \
  --manifest "${MANIFEST}" \
  --images-dir "${IMAGES_DIR}" \
  --history "${HISTORY}" \
  --threshold-days "${THRESHOLD_DAYS}" \
  --platform "${PLATFORM}" \
  "${EXTRA_ARGS[@]}" > "${TMP_LOG}" 2>&1
status=$?
set -e

tee -a "${LOG}" < "${TMP_LOG}" > /dev/null
rm -f "${TMP_LOG}"
exit "${status}"
