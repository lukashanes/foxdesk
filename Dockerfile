# FoxDesk self-hosted image (PHP 8.2 + Apache).
#
# The application source is baked into /usr/src/foxdesk. On start the
# entrypoint copies it into /var/www/html (a persistent volume) when that
# volume is empty or older than the image, so the in-app updater can keep
# writing release files there. See docs/DOCKER.md.

# Bookworm is pinned because the IMAP extension needs libc-client, which is
# not packaged for newer Debian releases.
FROM php:8.2-apache-bookworm

ARG DEBIAN_FRONTEND=noninteractive

# PHP extensions: pdo_mysql/mysqli (database), zip (backups and updates),
# intl (locale formatting), imap (email-to-ticket), opcache.
# Build-only -dev packages are removed afterwards; runtime libraries stay.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libc-client2007e-dev \
        libicu-dev \
        libkrb5-dev \
        libzip-dev \
    ; \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl; \
    docker-php-ext-install -j"$(nproc)" imap intl mysqli pdo_mysql zip; \
    docker-php-ext-enable opcache; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        mariadb-client \
        rsync \
    ; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qi '^imap$'; \
    php -m | grep -qi '^intl$'; \
    php -m | grep -qi '^pdo_mysql$'; \
    php -m | grep -qi '^zip$'; \
    php -m | grep -qi '^zend opcache$'

RUN set -eux; \
    a2enmod rewrite headers expires deflate; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Upload and memory limits can be overridden at runtime with these variables.
ENV PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=32M \
    PHP_POST_MAX_SIZE=40M \
    PHP_MAX_EXECUTION_TIME=120 \
    FOXDESK_CONFIG_DIR=/var/lib/foxdesk/config

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-foxdesk.ini"
COPY docker/apache.conf /etc/apache2/conf-available/foxdesk.conf
COPY docker/entrypoint.sh /usr/local/bin/foxdesk-entrypoint
COPY docker/healthcheck.sh /usr/local/bin/foxdesk-healthcheck
COPY docker/cron.php /usr/local/lib/foxdesk/cron.php

# Application source (filtered by .dockerignore to the release payload).
COPY . /usr/src/foxdesk

RUN set -eux; \
    a2enconf foxdesk; \
    rm -rf /usr/src/foxdesk/docker; \
    find /usr/src/foxdesk -type d -exec chmod 0755 {} +; \
    find /usr/src/foxdesk -type f -exec chmod 0644 {} +; \
    sed -i 's/\r$//' /usr/local/bin/foxdesk-entrypoint /usr/local/bin/foxdesk-healthcheck \
        "$PHP_INI_DIR/conf.d/zz-foxdesk.ini" /etc/apache2/conf-available/foxdesk.conf; \
    chmod 0755 /usr/local/bin/foxdesk-entrypoint /usr/local/bin/foxdesk-healthcheck; \
    test -f /usr/src/foxdesk/index.php; \
    test -f /usr/src/foxdesk/includes/schema.sql; \
    find /usr/src/foxdesk -type f -name '*.php' -print0 | xargs -0 -n1 -P"$(nproc)" php -l > /dev/null; \
    php -l /usr/local/lib/foxdesk/cron.php > /dev/null; \
    mkdir -p "$FOXDESK_CONFIG_DIR" /var/www/html; \
    chown www-data:www-data "$FOXDESK_CONFIG_DIR" /var/www/html; \
    chmod 0750 "$FOXDESK_CONFIG_DIR"; \
    apache2ctl -t

WORKDIR /var/www/html
VOLUME ["/var/www/html", "/var/lib/foxdesk/config"]

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD ["foxdesk-healthcheck"]

ENTRYPOINT ["foxdesk-entrypoint"]
CMD ["apache2-foreground"]
