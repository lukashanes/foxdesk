# Running FoxDesk with Docker Compose

The repository includes a `Dockerfile` and a `docker-compose.yml` for running
the self-hosted edition on any machine with Docker. The stack is the same
application as a ZIP installation: the web installer, the in-app updater,
backups, and recovery all work the same way.

- [What the stack contains](#what-the-stack-contains)
- [Quick start](#quick-start)
- [Background tasks (cron)](#background-tasks-cron)
- [Updating FoxDesk](#updating-foxdesk)
- [Backups and restore](#backups-and-restore)
- [HTTPS and reverse proxies](#https-and-reverse-proxies)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

## What the stack contains

| Service | Image | Purpose |
| --- | --- | --- |
| `app` | built from `Dockerfile` (PHP 8.2, Apache) | Web server. Publishes port 8080 on `127.0.0.1` by default. |
| `cron` | same image | Runs FoxDesk background tasks once a minute. |
| `db` | `mariadb:11` | Database. Not published outside the Compose network. |

The image includes the PHP extensions FoxDesk uses: `pdo_mysql`, `mysqli`,
`mbstring`, `intl`, `zip`, `curl`, `fileinfo`, `imap` (email-to-ticket), and
OPcache. Apache has `mod_rewrite`, `mod_headers`, `mod_expires`, and
`mod_deflate` enabled.

Data lives in named volumes:

| Volume | Mounted at | Contents |
| --- | --- | --- |
| `app` | `/var/www/html` | Application files. The in-app updater writes here. |
| `config` | `/var/lib/foxdesk/config` | `config.php`. `/var/www/html/config.php` is a symlink to it. |
| `uploads` | `/var/www/html/uploads` | Uploaded files. |
| `storage` | `/var/www/html/storage` | Sessions and email attachments. |
| `backups` | `/var/www/html/backups` | Backups made by the in-app updater and by container upgrades. |
| `db` | `/var/lib/mysql` | MariaDB data. |

Compose prefixes volume names with the project name, for example
`foxdesk_uploads` when the repository folder is called `foxdesk`.

## Quick start

Requirements: Docker Engine with the Compose plugin (`docker compose`).

```bash
git clone https://github.com/lukashanes/foxdesk.git
cd foxdesk
cp .env.example .env
```

Edit `.env` and set `DB_PASSWORD` and `DB_ROOT_PASSWORD` (Compose refuses to
start without them). One way to generate a value is `openssl rand -hex 24`.
Then build and start the stack:

```bash
docker compose up -d --build
docker compose ps        # app and db should report "healthy"
```

Open `http://localhost:8080/install.php` and complete the two installer steps.
In the database step enter:

| Field | Value |
| --- | --- |
| Database host | `db` |
| Port | `3306` |
| Database name | `DB_NAME` from `.env` (default `foxdesk`) |
| Database user | `DB_USER` from `.env` (default `foxdesk`) |
| Password | `DB_PASSWORD` from `.env` |

The installer creates the database schema, applies the bundled migrations,
and writes `config.php` into the `config` volume, so it survives container
rebuilds and in-app updates. Once `config.php` exists, `install.php`
redirects to the application and cannot be run again without the recovery
token, so you do not need to delete it inside the container.

> The installer stores the URL you used as `APP_URL` in `config.php`. If
> FoxDesk will be reached through a reverse proxy, run the installer through
> that public HTTPS address, or change `APP_URL` afterwards (see
> [Editing config.php](#editing-configphp)).

To try FoxDesk on another machine in your network without a proxy, set
`FOXDESK_HTTP_BIND=0.0.0.0` in `.env`. Note that FoxDesk redirects plain HTTP
to HTTPS for every host name except `localhost` and `127.0.0.1`, so a real
deployment needs HTTPS in front (see below).

### Prebuilt image instead of building locally

Every release is also published as a multi-architecture image (amd64 and
arm64) on GitHub Container Registry, built from the same `Dockerfile`:

```
ghcr.io/lukashanes/foxdesk:<version>   # for example ghcr.io/lukashanes/foxdesk:0.3.151
ghcr.io/lukashanes/foxdesk:latest
```

To use it, pin the release version in `.env` and start the stack without a
local build:

```bash
echo "FOXDESK_IMAGE=ghcr.io/lukashanes/foxdesk:0.3.151" >> .env
docker compose pull app cron
docker compose up -d --no-build
```

Pin a version rather than `latest`, so a restart never changes the release
unexpectedly. To move to a newer release, either use the in-app updater as
described below, or change the version in `FOXDESK_IMAGE` and run the same
`pull` and `up -d --no-build` commands; the container backs up and upgrades
the persistent application files exactly as with a locally built image.

## Background tasks (cron)

The `cron` service runs `/usr/local/lib/foxdesk/cron.php` every
`FOXDESK_CRON_INTERVAL` seconds (default 60) as `www-data`. It executes the
same task runner as the built-in pseudo-cron endpoint: IMAP email ingest,
recurring tickets, due-date reminders, scheduled reports, attachment cleanup,
notification cleanup, and the daily update check. Each task keeps its own
interval and a shared lock prevents overlapping runs, so the one-minute loop
does not run tasks more often than intended.

Until FoxDesk is installed the service waits and logs that it is waiting.
Check it with:

```bash
docker compose logs -f cron
```

The pseudo-cron (tasks triggered by page loads) stays enabled after
installation. It shares the same lock, so leaving it on is harmless; you can
turn it off in **Admin > Settings** once the `cron` service is running.

## Updating FoxDesk

There are two ways to move a Docker installation to a new FoxDesk release.

| | In-app updater (recommended) | Rebuilding the image |
| --- | --- | --- |
| How | **Admin > Settings > System**, review the changelog, install. | `git pull`, then `docker compose up -d --build`. |
| Backup | Automatic files and database backup, listed in the admin UI. | Automatic database dump and files archive in `backups/docker-upgrades/`. |
| Database migrations | Run by the updater. | Run by the container (`php upgrade.php`) on start. |
| Rollback | One click from the backup list. | Restore the dump and archive manually. |
| Updates PHP, Apache, OS packages | No | Yes |

**Recommendation:** install FoxDesk releases with the in-app updater, exactly
as on any other server, and rebuild the image periodically to pick up PHP,
Apache, and Debian security fixes:

```bash
git pull
docker compose build --pull
docker compose pull db
docker compose up -d
```

How the two paths fit together:

- Application files live in the `app` volume, so files installed by the in-app
  updater survive container restarts, recreation, and image rebuilds.
- When the container starts, it compares `APP_VERSION` in the image with the
  version in the `app` volume:
  - **volume empty** - the image files are copied in (first start);
  - **image newer** - the container writes a backup to
    `backups/docker-upgrades/<time>_<old>_to_<new>/` (`database.sql.gz` and
    `files.tar.gz`), copies the new release files, removes files that the
    release no longer ships from `assets`, `bin`, `includes`, `locales`,
    `migrations`, and `pages`, and runs `php upgrade.php`;
  - **volume newer** (installed by the in-app updater) - the volume is kept;
    the image is never used to downgrade FoxDesk.
- If the pre-upgrade backup fails, the container keeps the old files and logs
  the reason. Set `FOXDESK_UPGRADE_BACKUP=0` only if you have your own backup.

`config.php`, uploads, storage, and backups are never touched by either path.

The `db` service uses the `mariadb:11` tag with `MARIADB_AUTO_UPGRADE=1`, so a
newer 11.x MariaDB pulled with `docker compose pull` upgrades its system
tables on start. Pin a specific tag such as `mariadb:11.8` in
`docker-compose.yml` if you prefer to choose when that happens.

## Backups and restore

The in-app backups in **Admin > Settings > System** contain the database and
the application files. They do not include uploads or storage. For a complete
backup, save the database and the data volumes:

```bash
# Database dump
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > foxdesk-db-$(date +%F).sql

# Uploads, storage, and config.php
docker compose exec -T app tar -czf - -C /var/www/html uploads storage -C /var/lib/foxdesk config > foxdesk-files-$(date +%F).tar.gz
```

Store both files outside the server. Keep `config.php` private: it contains
the database password and `SECRET_KEY`.

To restore into a fresh stack (same `.env`):

```bash
docker compose up -d
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < foxdesk-db-YYYY-MM-DD.sql
docker compose exec -T app tar -xzf - -C /var/lib/foxdesk config < foxdesk-files-YYYY-MM-DD.tar.gz
docker compose exec -T app tar -xzf - -C /var/www/html uploads storage < foxdesk-files-YYYY-MM-DD.tar.gz
docker compose restart app cron
```

The restart reapplies file ownership. If the backup came from a newer FoxDesk
release than the image, install that release again with the in-app updater.

## HTTPS and reverse proxies

FoxDesk redirects HTTP requests to HTTPS unless the host is `localhost` or
`127.0.0.1`, or the request carries `X-Forwarded-Proto: https`. Run a TLS
reverse proxy in front of the `app` service; Caddy and Traefik send the
forwarded headers by default.

FoxDesk accepts `X-Forwarded-*` headers from private network addresses, which
includes Docker networks. Keep the published port on `127.0.0.1` (the
default) or remove it, so that only your proxy can reach the container.

### Caddy on the host

With the default `FOXDESK_HTTP_BIND=127.0.0.1` and `FOXDESK_HTTP_PORT=8080`:

```caddyfile
helpdesk.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

### Traefik in Docker

Create `docker-compose.override.yml` next to `docker-compose.yml` (Compose
loads it automatically) and attach `app` to the network your Traefik instance
uses:

```yaml
services:
  app:
    ports: !reset []
    networks: [default, traefik]
    labels:
      - traefik.enable=true
      - traefik.docker.network=traefik
      - traefik.http.routers.foxdesk.rule=Host(`helpdesk.example.com`)
      - traefik.http.routers.foxdesk.entrypoints=websecure
      - traefik.http.routers.foxdesk.tls.certresolver=letsencrypt
      - traefik.http.services.foxdesk.loadbalancer.server.port=80

networks:
  traefik:
    external: true
```

Adjust the network, entry point, and certificate resolver names to your
Traefik configuration. `!reset` requires Docker Compose 2.24 or newer.

### Editing config.php

`config.php` is in the `config` volume. To change `APP_URL`, the time zone,
or IMAP settings:

```bash
docker compose cp app:/var/lib/foxdesk/config/config.php ./config.php
# edit ./config.php
docker compose cp ./config.php app:/var/lib/foxdesk/config/config.php
docker compose restart app cron
rm ./config.php
```

The installer writes the `TZ` value from `.env` (UTC by default) into
`config.php` with `date_default_timezone_set()`. Set `TZ` before running the
installer, or change both later so that PHP and MariaDB agree on the current
time.

## Configuration reference

Variables read from `.env`:

| Variable | Default | Purpose |
| --- | --- | --- |
| `FOXDESK_HTTP_BIND` | `127.0.0.1` | Host address for the published web port. |
| `FOXDESK_HTTP_PORT` | `8080` | Host port for the web server. |
| `DB_NAME` | `foxdesk` | Database created on first start. |
| `DB_USER` | `foxdesk` | Database user created on first start. |
| `DB_PASSWORD` | required | Password for `DB_USER`. |
| `DB_ROOT_PASSWORD` | required | MariaDB root password. |
| `TZ` | `UTC` | Time zone of the containers and the default written to `config.php` by the installer. |
| `PHP_UPLOAD_MAX_FILESIZE` | `32M` | PHP upload limit. FoxDesk's own limit is `MAX_UPLOAD_SIZE` in `config.php` (10 MB by default). |
| `PHP_POST_MAX_SIZE` | `40M` | PHP request body limit. |
| `PHP_MEMORY_LIMIT` | `256M` | PHP memory limit. |
| `FOXDESK_CRON_INTERVAL` | `60` | Seconds between background task runs (minimum 30). |
| `FOXDESK_UPGRADE_BACKUP` | `1` | Back up before the container upgrades application files from a newer image. |
| `FOXDESK_IMAGE` | `foxdesk-selfhosted:local` | Tag of the locally built image, or a pinned prebuilt image such as `ghcr.io/lukashanes/foxdesk:0.3.151`. |

`DB_NAME`, `DB_USER`, and the passwords only take effect when the `db` volume
is created. Changing them later does not change the existing database.

## Troubleshooting

**Logs.** `docker compose logs app`, `docker compose logs cron`, and
`docker compose logs db`. PHP errors are written to the `app` log.

**The in-app updater reports a directory or file is not writable.** Restart
the container with `docker compose restart app`; the entrypoint gives
`www-data` ownership of the application files again. This can happen after
copying files in with `docker cp` or `docker compose cp`.

**The browser is redirected to `https://` and fails.** You opened FoxDesk by
IP address or host name over plain HTTP. Use `http://localhost:<port>` for
local access or put an HTTPS proxy in front.

**"Database upgrade required".** Run
`docker compose exec -u www-data app php upgrade.php`.

**Start over.** `docker compose down` stops the stack and keeps all volumes.
`docker compose down -v` deletes every volume, including the database and
uploads.

**Security notes.** The web server user owns the application files so that
the in-app updater can replace them, as on typical PHP hosting. The database
is reachable only inside the Compose network, `config.php` is readable only by
`www-data`, and `backups/`, `storage/`, `uploads/`, `includes/`, and `bin/`
are blocked from direct web access.
