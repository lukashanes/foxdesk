#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-}"

if [ -z "$PHP_BIN" ]; then
    if command -v php >/dev/null 2>&1; then
        PHP_BIN="php"
    elif command -v docker >/dev/null 2>&1 && docker image inspect php:8.2-cli >/dev/null 2>&1; then
        PHP_BIN="docker run --rm -v $ROOT_DIR:/app -w /app php:8.2-cli php"
    else
        echo "PHP CLI was not found."
        echo "Install it with: brew install php"
        echo "Or pull the Docker fallback with: docker pull php:8.2-cli"
        exit 127
    fi
fi

cd "$ROOT_DIR"

find . -name '*.php' \
    -not -path './vendor/*' \
    -not -path './node_modules/*' \
    -print | sort | while IFS= read -r file; do
        # shellcheck disable=SC2086
        $PHP_BIN -l "$file" >/dev/null
        echo "OK $file"
    done
