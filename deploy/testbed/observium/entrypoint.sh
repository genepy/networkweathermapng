#!/bin/sh
# Writes config.php from the environment, prepares the database, adds the
# simulated devices, then runs the scheduler and Apache.
set -e

cd /opt/observium

php_quote() {
    printf "'%s'" "$(printf '%s' "$1" | sed "s/[\\\\']/\\\\&/g")"
}

cat > config.php <<PHP
<?php
// Written by the container at start: edit the environment, not this file.
\$config['db_host'] = $(php_quote "${OBSERVIUM_DB_HOST:-db}");
\$config['db_name'] = $(php_quote "${OBSERVIUM_DB_NAME:-observium}");
\$config['db_user'] = $(php_quote "${OBSERVIUM_DB_USER:-observium}");
\$config['db_pass'] = $(php_quote "${OBSERVIUM_DB_PASSWORD}");
\$config['install_dir'] = '/opt/observium';
\$config['rrd_dir'] = '/opt/observium/rrd';
\$config['log_dir'] = '/opt/observium/logs';
\$config['rrdcached'] = $(php_quote "${OBSERVIUM_RRDCACHED:-rrdcached:42217}");
\$config['fping'] = '/usr/sbin/fping';
\$config['fping6'] = '/usr/sbin/fping6';
\$config['snmp']['version'] = 'v2c';
\$config['snmp']['community'] = [$(php_quote "${OBSERVIUM_SNMP_COMMUNITY:-public}")];
PHP
# Without base_url, Observium builds its links from the request (Host header,
# and https when X-Forwarded-Proto says so). base_url pins them to one public
# address; external_url is the same address for links made by the scheduler.
if [ -n "$OBSERVIUM_BASE_URL" ]; then
    echo "\$config['base_url'] = $(php_quote "$OBSERVIUM_BASE_URL");" >> config.php
    echo "\$config['external_url'] = $(php_quote "$OBSERVIUM_BASE_URL");" >> config.php
fi
chmod 640 config.php
chown root:www-data config.php
mkdir -p /tmp/opcache logs rrd

echo "Waiting for the database..."
until mysqladmin ping -h "${OBSERVIUM_DB_HOST:-db}" -u "${OBSERVIUM_DB_USER:-observium}" \
        -p"${OBSERVIUM_DB_PASSWORD}" --silent 2>/dev/null; do
    sleep 3
done

# Creates the schema on the first start, upgrades it after that
php discovery.php -u -q

if [ -n "$OBSERVIUM_ADMIN_PASSWORD" ]; then
    php adduser.php "${OBSERVIUM_ADMIN_USER:-admin}" "$OBSERVIUM_ADMIN_PASSWORD" 10 >/dev/null 2>&1 || true
fi

# Devices to monitor, "host" or "host:community", separated by spaces.
# add_device.php leaves a device that is already there untouched; a device
# that does not answer yet is tried again a few times.
for device in $OBSERVIUM_DEVICES; do
    host=${device%%:*}
    community=${device#*:}
    [ "$community" = "$device" ] && community=${OBSERVIUM_SNMP_COMMUNITY:-public}
    for attempt in 1 2 3 4 5; do
        result=$(php add_device.php "$host" "$community" v2c 2>&1 || true)
        if echo "$result" | grep -qiE "added|already"; then
            echo "$result" | grep -iE "added|already" | head -n 1
            break
        fi
        [ "$attempt" -eq 5 ] && echo "Could not add $host:" && echo "$result" | tail -n 3
        sleep 5
    done
done

/usr/local/bin/observium-scheduler &

exec httpd -D FOREGROUND
