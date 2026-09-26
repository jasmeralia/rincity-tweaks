#!/usr/bin/env bash
set -euo pipefail

VENV_PY="/home/morgan/rincity-tweaks/rincity-throwback-posts/.venv/bin/python"
SCRIPT="/home/morgan/rincity-tweaks/rincity-throwback-posts/list_history.py"
WORKDIR="/home/morgan/rincity-tweaks/rincity-throwback-posts"
HISTORY="/home/morgan/rincity-tweaks/rincity-throwback-posts/post_history.json"

cd "${WORKDIR}"
exec "${VENV_PY}" "${SCRIPT}" \
  --history "${HISTORY}" \
  "$@"
