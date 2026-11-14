#!/usr/bin/env bash
set -euo pipefail

VENV_PY="/home/morgan/rincity-tweaks/rincity-throwback-posts/.venv/bin/python"
SCRIPT="/home/morgan/rincity-tweaks/rincity-throwback-posts/rin_throwback_post.py"
NEW_SET_CHECK="/home/morgan/rincity-tweaks/rincity-throwback-posts/check_new_set_today.php"
WORKDIR="/home/morgan/rincity-tweaks/rincity-throwback-posts"
WP_CLI="/home/morgan/bin/wp-lsphp"
WP_ROOT="/usr/local/lsws/wordpress"
MANIFEST="/usr/local/lsws/wordpress/wp-content/uploads/Rin_Covers/manifest.json"
IMAGES_DIR="/usr/local/lsws/wordpress/wp-content/uploads/Rin_Covers"
HISTORY="/home/morgan/rincity-tweaks/rincity-throwback-posts/post_history.json"
EXCLUDE_FILE="/home/morgan/rincity-tweaks/rincity-throwback-posts/excludes.json"
LOG="/home/morgan/rincity-tweaks/rincity-throwback-posts/cron.log"
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
DB_CHECK_RESULT="$("${WP_CLI}" --path="${WP_ROOT}" eval-file "${NEW_SET_CHECK}" 2>&1)"
db_check_status=$?
set -e

if [[ "${db_check_status}" -ne 0 ]]; then
  printf '%s\n' "${DB_CHECK_RESULT}" > "${TMP_LOG}"
  run_status="${db_check_status}"
elif [[ "${DB_CHECK_RESULT}" == SKIP:* ]]; then
  printf '%s\n' "${DB_CHECK_RESULT}" > "${TMP_LOG}"
  run_status=0
elif [[ "${DB_CHECK_RESULT}" == "POST" ]]; then
  set +e
  "${VENV_PY}" "${SCRIPT}" \
    --manifest "${MANIFEST}" \
    --images-dir "${IMAGES_DIR}" \
    --history "${HISTORY}" \
    --exclude-file "${EXCLUDE_FILE}" \
    --platform "${PLATFORM}" \
    "${EXTRA_ARGS[@]}" > "${TMP_LOG}" 2>&1
  run_status=$?
  set -e
else
  printf 'ERROR: unexpected WP-CLI set-check output:\n%s\n' "${DB_CHECK_RESULT}" > "${TMP_LOG}"
  run_status=1
fi

tee -a "${LOG}" < "${TMP_LOG}" > /dev/null

if [[ "${run_status}" -ne 0 ]]; then
  # Surface failures on our own stdout/stderr (not just cron.log) so cron's
  # MAILTO mail delivery actually fires, and so the CRON journal gets the
  # "MAIL" line the OpenSearch daily-digest cron_failures check looks for.
  {
    echo "run_throwback.sh failed (exit ${run_status}) at $(date -u +%FT%TZ)"
    echo "---- output ----"
    cat "${TMP_LOG}"
  } >&2
fi

rm -f "${TMP_LOG}"
exit "${run_status}"
