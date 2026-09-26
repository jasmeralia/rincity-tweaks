#!/usr/bin/env bash
set -euo pipefail

VENV_PY="/home/morgan/rincity-tweaks/rincity-throwback-posts/.venv/bin/python"
SCRIPT="/home/morgan/rincity-tweaks/rincity-throwback-posts/list_eligible.py"
WORKDIR="/home/morgan/rincity-tweaks/rincity-throwback-posts"
MANIFEST="/usr/local/lsws/wordpress/wp-content/uploads/Rin_Covers/manifest.json"
HISTORY="/home/morgan/rincity-tweaks/rincity-throwback-posts/post_history.json"
EXCLUDE_FILE="/home/morgan/rincity-tweaks/rincity-throwback-posts/excludes.json"

cd "${WORKDIR}"
exec "${VENV_PY}" "${SCRIPT}" \
  --manifest "${MANIFEST}" \
  --history "${HISTORY}" \
  --exclude-file "${EXCLUDE_FILE}" \
  "$@"
