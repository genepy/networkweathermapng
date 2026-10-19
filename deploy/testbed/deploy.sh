#!/bin/sh
# Builds, starts and seeds the testbed on this machine. Run by the
# "Deploy testbed" workflow on the self-hosted runner, or by hand.
#
# Passwords are generated on the first run and kept outside the checkout, in
# $TESTBED_ENV_FILE (default ~/.config/weathermap-testbed/env). Edit that file
# to change ports, passwords or the monitored devices, then run this again.
# WEATHERMAP_PUBLIC_URL and OBSERVIUM_PUBLIC_URL, when set there, are the
# addresses printed at the end (behind a reverse proxy).
#
# With $TESTBED_BACKUP_DIR set, the running version's data (Observium's
# database, RRD files, Weathermap's data) is saved there before the new
# version starts; the last 10 backups are kept. If the backup fails, the
# running version is left as it is.
set -eu

cd "$(dirname "$0")"

env_file=${TESTBED_ENV_FILE:-$HOME/.config/weathermap-testbed/env}

random_password() {
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24
}

if [ ! -f "$env_file" ]; then
    echo "Creating $env_file with new passwords"
    mkdir -p "$(dirname "$env_file")"
    umask 077
    cat > "$env_file" <<ENV
# Weathermap testbed settings (see deploy/testbed/README.md)
TZ=UTC
WEATHERMAP_PORT=8080
OBSERVIUM_PORT=8668
WEATHERMAP_ADMIN_PASSWORD=$(random_password)
OBSERVIUM_ADMIN_PASSWORD=$(random_password)
DB_ROOT_PASSWORD=$(random_password)
OBSERVIUM_DB_PASSWORD=$(random_password)
WEATHERMAP_DB_PASSWORD=$(random_password)
ENV
fi

compose() {
    docker compose --env-file "$env_file" "$@"
}

echo "Building the images (the first build takes a while on a Pi)"
compose build

# Each step returns on failure: "set -e" does not apply inside "if ! backup"
backup() {
    dir=$1
    stamp=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$dir" || return 1

    echo "Backing up the running version to $dir"
    compose exec -T db bash -o pipefail -c \
        'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction observium | gzip' \
        > "$dir/observium-db-$stamp.sql.gz" || return 1
    # Writes the RRD updates rrdcached still holds in memory
    compose exec -T rrdcached sh -c 'echo FLUSHALL | nc -w 10 127.0.0.1 42217' > /dev/null || return 1
    docker run --rm \
        -v weathermap-testbed_rrd:/backup/rrd:ro \
        -v weathermap-testbed_weathermap:/backup/weathermap:ro \
        -v "$dir":/out \
        alpine:3.20 tar czf "/out/volumes-$stamp.tar.gz" -C /backup rrd weathermap || return 1

    for pattern in 'observium-db-*.sql.gz' 'volumes-*.tar.gz'; do
        find "$dir" -maxdepth 1 -name "$pattern" | sort -r | tail -n +11 | xargs -r rm -f
    done
}

if [ -n "${TESTBED_BACKUP_DIR:-}" ] && [ -n "$(compose ps -q --status running db 2>/dev/null)" ]; then
    if ! backup "$TESTBED_BACKUP_DIR"; then
        echo "Backup failed: the running version is left untouched" >&2
        exit 1
    fi
fi

echo "Starting the containers"
compose up -d --remove-orphans

echo "Waiting for the containers to be ready"
ready=0
for _ in $(seq 1 60); do
    if compose exec -T weathermap curl -fsS -o /dev/null http://localhost/health 2>/dev/null \
        && compose exec -T observium curl -fsS -o /dev/null http://localhost/ 2>/dev/null; then
        ready=1
        break
    fi
    sleep 10
done
if [ "$ready" -ne 1 ]; then
    compose ps
    compose logs --tail 50 observium weathermap
    echo "The containers did not become ready" >&2
    exit 1
fi

compose exec -T -u www-data weathermap php /testbed/seed.php

# Old images left by previous builds take space on the card
docker image prune -f > /dev/null

. "$env_file"
# Public addresses when a reverse proxy is in front, else the published ports
# (on this machine only when they listen on a single address).
case ${BIND_ADDRESS:-0.0.0.0} in
    0.0.0.0 | ::)
        host=$(hostname -I 2>/dev/null | awk '{print $1}')
        host=${host:-localhost}
        ;;
    *:*) host="[$BIND_ADDRESS]" ;;
    *) host=$BIND_ADDRESS ;;
esac
weathermap_url=${WEATHERMAP_PUBLIC_URL:-http://$host:${WEATHERMAP_PORT:-8080}}
observium_url=${OBSERVIUM_PUBLIC_URL:-${OBSERVIUM_BASE_URL:-http://$host:${OBSERVIUM_PORT:-8668}}}
echo
echo "Weathermap: $weathermap_url  (admin, password in $env_file)"
echo "Observium:  $observium_url  (admin, password in $env_file)"
echo "The map shows the simulated traffic about a minute after the first start."
