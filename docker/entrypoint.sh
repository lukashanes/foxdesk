#!/bin/sh
# FoxDesk container entrypoint.
#
#   apache2-foreground  (default) prepare volumes, install or upgrade the
#                       application files, then start Apache.
#   foxdesk-cron        run FoxDesk background tasks every
#                       FOXDESK_CRON_INTERVAL seconds (scheduler sidecar).
#
# Layout:
#   /usr/src/foxdesk          application files shipped in the image (read only)
#   /var/www/html             live application files (volume, written by the
#                             in-app updater as www-data)
#   /var/www/html/uploads     uploads volume
#   /var/www/html/storage     storage volume (sessions, email attachments)
#   /var/www/html/backups     backups volume
#   $FOXDESK_CONFIG_DIR       config volume; /var/www/html/config.php is a
#                             symlink to config.php in this directory
set -eu

APP_ROOT=/var/www/html
SRC_ROOT=/usr/src/foxdesk
CONFIG_DIR="${FOXDESK_CONFIG_DIR:-/var/lib/foxdesk/config}"
CRON_SCRIPT=/usr/local/lib/foxdesk/cron.php
# Directories owned by the release; files removed from a release are pruned
# from these on an image upgrade (the in-app rollback treats them the same way).
MANAGED_DIRS="assets bin includes locales migrations pages"
DATA_DIRS="uploads storage backups"

log() {
    printf '[foxdesk] %s\n' "$*" >&2
}

as_www_data() {
    setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
}

# Print APP_VERSION from <dir>/index.php, or nothing when it is absent.
read_app_version() {
    [ -f "$1/index.php" ] || return 0
    sed -n "s/^[[:space:]]*define([[:space:]]*['\"]APP_VERSION['\"][[:space:]]*,[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" "$1/index.php" | head -n 1
}

# Succeeds when version $1 is newer than version $2.
version_gt() {
    php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"
}

is_installed() {
    [ -f "$APP_ROOT/config.php" ]
}

# Keep config.php in the config volume and expose it to the app via a symlink.
link_config() {
    target="$CONFIG_DIR/config.php"
    link="$APP_ROOT/config.php"

    mkdir -p "$CONFIG_DIR"
    chown www-data:www-data "$CONFIG_DIR"
    chmod 0750 "$CONFIG_DIR"

    if [ -L "$link" ]; then
        if [ "$(readlink "$link")" != "$target" ]; then
            ln -sfn "$target" "$link"
        fi
    elif [ -e "$link" ]; then
        if [ -e "$target" ]; then
            log "WARNING: $link and $target both exist; using $link."
            log "WARNING: move the correct file to $target and delete the other one."
            return 0
        fi
        log "Moving config.php into the config volume ($target)"
        mv "$link" "$target"
        ln -s "$target" "$link"
    else
        ln -s "$target" "$link"
    fi

    if [ -f "$target" ]; then
        chown www-data:www-data "$target"
        chmod 0640 "$target"
    fi
}

ensure_runtime_dirs() {
    for dir in uploads storage storage/sessions storage/tickets backups; do
        mkdir -p "$APP_ROOT/$dir"
    done
    for dir in $DATA_DIRS; do
        find "$APP_ROOT/$dir" ! -user www-data -exec chown -h www-data:www-data {} +
    done
}

# The in-app updater runs as www-data and must be able to replace release
# files and create .maintenance in the application root.
chown_application_files() {
    find "$APP_ROOT" \
        \( -path "$APP_ROOT/uploads" -o -path "$APP_ROOT/storage" -o -path "$APP_ROOT/backups" \) -prune \
        -o ! -user www-data -exec chown -h www-data:www-data {} +
}

# $1 = seed (empty volume) or upgrade (image newer than installed files)
copy_application_files() {
    rsync -a --chown=www-data:www-data \
        --exclude=/config.php \
        --exclude=/uploads/ \
        --exclude=/storage/ \
        --exclude=/backups/ \
        "$SRC_ROOT/" "$APP_ROOT/"

    if [ "$1" = upgrade ]; then
        for dir in $MANAGED_DIRS; do
            if [ -d "$SRC_ROOT/$dir" ]; then
                rsync -a --delete --chown=www-data:www-data "$SRC_ROOT/$dir/" "$APP_ROOT/$dir/"
            fi
        done
    fi
}

# Database dump (and current application files, if any) before the entrypoint
# changes code for an installed FoxDesk. $1 = old version, $2 = new version.
backup_before_upgrade() {
    if [ "${FOXDESK_UPGRADE_BACKUP:-1}" = "0" ]; then
        log "Skipping pre-upgrade backup (FOXDESK_UPGRADE_BACKUP=0)"
        return 0
    fi

    stamp="$(date -u +%Y%m%d-%H%M%S)"
    dir="$APP_ROOT/backups/docker-upgrades/${stamp}_${1:-none}_to_$2"
    mkdir -p "$dir" || return 1

    if [ -f "$APP_ROOT/index.php" ]; then
        tar -C "$APP_ROOT" -czf "$dir/files.tar.gz" \
            --exclude=./uploads --exclude=./storage --exclude=./backups --exclude=./config.php \
            . || return 1
    fi

    db_info="$(php -d display_errors=stderr -r '
        require $argv[1];
        foreach (["DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS"] as $name) {
            echo defined($name) ? str_replace("\n", "", (string) constant($name)) : "", "\n";
        }' "$CONFIG_DIR/config.php")" || return 1
    db_host="$(printf '%s\n' "$db_info" | sed -n 1p)"
    db_port="$(printf '%s\n' "$db_info" | sed -n 2p)"
    db_name="$(printf '%s\n' "$db_info" | sed -n 3p)"
    db_user="$(printf '%s\n' "$db_info" | sed -n 4p)"
    db_pass="$(printf '%s\n' "$db_info" | sed -n 5p)"

    MYSQL_PWD="$db_pass" mariadb-dump \
        --host="$db_host" --port="${db_port:-3306}" --user="$db_user" \
        --single-transaction --quick --routines --triggers --no-tablespaces \
        --default-character-set=utf8mb4 \
        "$db_name" > "$dir/database.sql" || return 1
    gzip -f "$dir/database.sql" || return 1

    chown -R www-data:www-data "$APP_ROOT/backups/docker-upgrades"
    chmod -R go-rwx "$dir"
    log "Pre-upgrade backup written to backups/docker-upgrades/$(basename "$dir")"
}

# Run the idempotent database upgrade script and surface its result lines.
run_database_upgrade() {
    log "Running database upgrade (php upgrade.php)"
    if ! output="$(cd "$APP_ROOT" && as_www_data php upgrade.php 2>&1)"; then
        log "ERROR: upgrade.php failed:"
        printf '%s\n' "$output" | tail -n 20 >&2
        return 1
    fi
    printf '%s\n' "$output" | sed -n 's/.*<span>\(.*\)<\/span>.*/\1/p' | while IFS= read -r line; do
        log "  $line"
    done
    if printf '%s\n' "$output" | grep -qiE 'ERROR:|failed|changed after it was applied'; then
        log "WARNING: the database upgrade reported errors; see the lines above."
    fi
}

prepare_application() {
    mkdir -p "$APP_ROOT"
    chown www-data:www-data "$APP_ROOT"

    image_version="$(read_app_version "$SRC_ROOT")"
    installed_version="$(read_app_version "$APP_ROOT")"
    if [ -z "$image_version" ]; then
        log "ERROR: cannot read APP_VERSION from $SRC_ROOT/index.php"
        exit 1
    fi

    link_config
    ensure_runtime_dirs

    code_changed=0
    run_upgrade=1
    if [ -z "$installed_version" ]; then
        log "Copying FoxDesk $image_version application files into $APP_ROOT"
        if is_installed; then
            log "WARNING: config.php exists but the application volume was empty."
            log "WARNING: if this installation was updated in-app to a newer release, install that update again."
            if ! backup_before_upgrade "" "$image_version"; then
                log "WARNING: pre-upgrade backup failed; the database upgrade will be skipped."
                run_upgrade=0
            fi
        fi
        copy_application_files seed
        code_changed=1
    elif version_gt "$image_version" "$installed_version"; then
        log "Upgrading application files from $installed_version to $image_version"
        if is_installed && ! backup_before_upgrade "$installed_version" "$image_version"; then
            log "ERROR: pre-upgrade backup failed; keeping application files at $installed_version."
            log "ERROR: fix the cause shown above, or set FOXDESK_UPGRADE_BACKUP=0 to upgrade without a backup."
        else
            copy_application_files upgrade
            code_changed=1
        fi
    elif version_gt "$installed_version" "$image_version"; then
        log "Application files ($installed_version) are newer than this image ($image_version);"
        log "keeping them (they were most likely installed by the in-app updater)."
    else
        log "FoxDesk $installed_version application files are current"
    fi

    # Recreate the symlink in case copied files replaced it.
    link_config
    chown_application_files

    if [ "$code_changed" = 1 ] && [ "$run_upgrade" = 1 ] && is_installed; then
        run_database_upgrade || log "WARNING: continuing; run 'php upgrade.php' as www-data after fixing the problem."
    fi

    if ! is_installed; then
        log "FoxDesk is not installed yet: open /install.php and use database host 'db'."
    fi
}

run_scheduler() {
    interval="${FOXDESK_CRON_INTERVAL:-60}"
    case "$interval" in
        '' | *[!0-9]*)
            log "Invalid FOXDESK_CRON_INTERVAL '$interval'; using 60"
            interval=60
            ;;
    esac
    if [ "$interval" -lt 30 ]; then
        interval=30
    fi

    trap 'log "Scheduler stopping"; exit 0' TERM INT
    log "Scheduler started; running FoxDesk background tasks every ${interval}s"

    waiting=0
    reported_ok=0
    while :; do
        if is_installed && [ -f "$APP_ROOT/pages/cron.php" ]; then
            if [ "$waiting" = 1 ]; then
                log "FoxDesk installation detected"
                waiting=0
            fi
            if output="$(php "$CRON_SCRIPT" 2>&1)"; then
                status=0
            else
                status=$?
            fi
            if [ "$status" -ne 0 ] || [ "$output" != "OK" ]; then
                log "Background task run (exit $status): $output"
            elif [ "$reported_ok" = 0 ] || [ "${FOXDESK_CRON_VERBOSE:-0}" = "1" ]; then
                log "Background task run: OK"
                reported_ok=1
            fi
        elif [ "$waiting" = 0 ]; then
            log "FoxDesk is not installed yet; waiting for the web installer"
            waiting=1
        fi
        sleep "$interval" &
        wait $! || true
    done
}

case "${1:-}" in
    foxdesk-cron)
        if [ "$(id -u)" = "0" ]; then
            exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$0" "$@"
        fi
        run_scheduler
        ;;
    apache2-foreground | apache2ctl)
        if [ "$(id -u)" = "0" ]; then
            prepare_application
        else
            log "Not running as root; skipping volume preparation"
        fi
        exec "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
