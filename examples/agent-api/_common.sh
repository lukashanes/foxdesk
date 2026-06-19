#!/usr/bin/env sh
set -eu

if [ -z "${SCRIPT_DIR:-}" ]; then
  SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
fi

ENV_FILE="${FOXDESK_AGENT_ENV:-}"
if [ -z "$ENV_FILE" ] && [ -f "$SCRIPT_DIR/.env" ]; then
  ENV_FILE="$SCRIPT_DIR/.env"
fi

if [ -n "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  set -a
  . "$ENV_FILE"
  set +a
fi

: "${FOXDESK_BASE_URL:?Set FOXDESK_BASE_URL in examples/agent-api/.env}"
: "${FOXDESK_API_TOKEN:?Set FOXDESK_API_TOKEN in examples/agent-api/.env}"

foxdesk_api_url() {
  action="$1"
  printf '%s/index.php?page=api&action=%s' "${FOXDESK_BASE_URL%/}" "$action"
}

foxdesk_json_escape() {
  printf '%s' "$1" \
    | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' \
    | awk '{ printf "%s%s", NR > 1 ? "\\n" : "", $0 }'
}

foxdesk_post_json() {
  action="$1"
  payload="$2"
  idempotency_key="${FOXDESK_IDEMPOTENCY_KEY:-agent-quickstart-$(date +%s)-$$}"

  curl -fsS -X POST "$(foxdesk_api_url "$action")" \
    -H "Authorization: Bearer ${FOXDESK_API_TOKEN}" \
    -H "Content-Type: application/json" \
    -H "Idempotency-Key: ${idempotency_key}" \
    --data "$payload"
}

foxdesk_get_json() {
  action="$1"
  query="${2:-}"
  url="$(foxdesk_api_url "$action")"
  if [ -n "$query" ]; then
    url="${url}&${query}"
  fi

  curl -fsS "$url" \
    -H "Authorization: Bearer ${FOXDESK_API_TOKEN}" \
    -H "Accept: application/json"
}

foxdesk_ticket_selector_json() {
  if [ -n "${FOXDESK_TICKET_HASH:-}" ]; then
    printf '"ticket_hash":"%s"' "$(foxdesk_json_escape "$FOXDESK_TICKET_HASH")"
    return
  fi
  if [ -n "${FOXDESK_TICKET_ID:-}" ]; then
    printf '"ticket_id":%s' "$FOXDESK_TICKET_ID"
    return
  fi

  echo "Set FOXDESK_TICKET_ID or FOXDESK_TICKET_HASH in examples/agent-api/.env" >&2
  exit 2
}
