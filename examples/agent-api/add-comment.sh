#!/usr/bin/env sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/_common.sh"

comment="${FOXDESK_COMMENT:-Quick note from the Agent API quickstart.}"
is_internal="${FOXDESK_INTERNAL_COMMENT:-0}"

payload="{"
payload="${payload}$(foxdesk_ticket_selector_json),"
payload="${payload}\"content\":\"$(foxdesk_json_escape "$comment")\","
payload="${payload}\"is_internal\":${is_internal}"
payload="${payload}}"

foxdesk_post_json "app-add-comment" "$payload"
