# Observium Community Edition for the testbed. Built locally, so it runs on
# arm64 (Raspberry Pi, Oracle Ampere) as well as amd64.
# Alpine 3.23 ships PHP 8.3.36, which fixes CVE-2026-109618 and
# CVE-2026-109619. Alpine 3.20 is end of life and stays on PHP 8.3.15.
FROM alpine:3.23

RUN apk add --no-cache \
        apache2 php83-apache2 php83 php83-cli php83-mysqli php83-pdo_mysql php83-gd \
        php83-bcmath php83-mbstring php83-opcache php83-pecl-apcu php83-curl \
        php83-session php83-ctype php83-posix php83-pcntl php83-sockets php83-simplexml \
        php83-xml php83-dom php83-iconv php83-zlib php83-fileinfo php83-gmp php83-intl \
        net-snmp-tools fping rrdtool mariadb-client whois \
        bash ca-certificates curl tini tzdata \
    && ln -sf /usr/bin/php83 /usr/bin/php

# The official tarball by default. To build offline, put
# observium-community-latest.tar.gz next to this Dockerfile.
ARG OBSERVIUM_URL=https://www.observium.org/observium-community-latest.tar.gz
COPY entrypoint.sh scheduler.sh observium-community-latest.tar.g[z] /tmp/build/
RUN set -e; \
    tarball=/tmp/build/observium-community-latest.tar.gz; \
    [ -f "$tarball" ] || curl -fsSL "$OBSERVIUM_URL" -o "$tarball"; \
    tar -xzf "$tarball" -C /opt; \
    install -m 755 /tmp/build/entrypoint.sh /usr/local/bin/observium-start; \
    install -m 755 /tmp/build/scheduler.sh /usr/local/bin/observium-scheduler; \
    rm -rf /tmp/build; \
    mkdir -p /opt/observium/rrd /opt/observium/logs

COPY apache.conf /etc/apache2/conf.d/observium.conf
COPY php.ini /etc/php83/conf.d/99-observium.ini
RUN sed -i \
        -e 's/^#\(LoadModule rewrite_module\)/\1/' \
        -e 's#^ErrorLog .*#ErrorLog /dev/stderr#' \
        -e 's#^\(\s*CustomLog .*\)#\#\1#' \
        -e 's#^Listen 80#Listen 80\nServerName localhost#' \
        /etc/apache2/httpd.conf

WORKDIR /opt/observium
EXPOSE 80

HEALTHCHECK --interval=60s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/observium-start"]
