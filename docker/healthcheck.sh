#!/bin/sh
# FoxDesk container health check.
# Before installation the installer page must load; afterwards the health
# endpoint must report a working database connection.
set -eu

base_url="http://127.0.0.1"

if [ -f /var/www/html/config.php ]; then
    body="$(curl -fsS --max-time 8 "$base_url/index.php?page=health")" || exit 1
    case "$body" in
        *'"status":"ok"'*) exit 0 ;;
        *)
            printf '%s\n' "$body"
            exit 1
            ;;
    esac
fi

curl -fsS --max-time 8 -o /dev/null "$base_url/install.php"
