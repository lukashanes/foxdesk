#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

if [ -n "${PHP_BIN:-}" ]; then
    exec "$PHP_BIN" "$@"
fi

if command -v php >/dev/null 2>&1; then
    exec php "$@"
fi

if command -v docker >/dev/null 2>&1 && docker image inspect php:8.2-cli >/dev/null 2>&1; then
    exec docker run --rm -v "$ROOT_DIR:/app" -w /app php:8.2-cli php "$@"
fi

echo "PHP CLI was not found."
echo "Install it with: brew install php"
echo "Or pull the Docker fallback with: docker pull php:8.2-cli"
exit 127
