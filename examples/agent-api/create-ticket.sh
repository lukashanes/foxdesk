#!/usr/bin/env sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/_common.sh"

title="${FOXDESK_TICKET_TITLE:-Agent API smoke ticket}"
description="${FOXDESK_TICKET_DESCRIPTION:-Created through the FoxDesk Agent API quickstart.}"

payload="{\"title\":\"$(foxdesk_json_escape "$title")\",\"description\":\"$(foxdesk_json_escape "$description")\""

if [ -n "${FOXDESK_ORGANIZATION_ID:-}" ]; then
  payload="${payload},\"organization_id\":${FOXDESK_ORGANIZATION_ID}"
fi
if [ -n "${FOXDESK_ASSIGNEE_ID:-}" ]; then
  payload="${payload},\"assignee_id\":${FOXDESK_ASSIGNEE_ID}"
fi
if [ -n "${FOXDESK_PRIORITY_ID:-}" ]; then
  payload="${payload},\"priority_id\":${FOXDESK_PRIORITY_ID}"
fi
if [ -n "${FOXDESK_STATUS_ID:-}" ]; then
  payload="${payload},\"status_id\":${FOXDESK_STATUS_ID}"
fi

payload="${payload}}"
foxdesk_post_json "app-create-ticket" "$payload"
