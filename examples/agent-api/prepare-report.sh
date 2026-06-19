#!/usr/bin/env sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/_common.sh"

time_range="${FOXDESK_TIME_RANGE:-this_month}"
limit="${FOXDESK_REPORT_LIMIT:-100}"
query="time_range=${time_range}&limit=${limit}"

if [ -n "${FOXDESK_ORGANIZATION_ID:-}" ]; then
  query="${query}&organization_id=${FOXDESK_ORGANIZATION_ID}"
fi

foxdesk_get_json "app-reporting-review" "$query"
