#!/bin/sh
# Builds, starts and seeds the testbed on this machine. Run by the
# "Deploy testbed" workflow on the self-hosted runner, or by hand.
#
# Passwords are generated on the first run and kept outside the checkout, in
# $TESTBED_ENV_FILE (default ~/.config/weathermap-testbed/env). Edit that file
# to change ports, passwords or the monitored devices, then run this again.
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

echo "Building and starting the containers (the first build takes a while on a Pi)"
compose up -d --build --remove-orphans

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
host=$(hostname -I 2>/dev/null | awk '{print $1}')
host=${host:-localhost}
echo
echo "Weathermap: http://$host:${WEATHERMAP_PORT:-8080}  (admin, password in $env_file)"
echo "Observium:  http://$host:${OBSERVIUM_PORT:-8668}  (admin, password in $env_file)"
echo "The map shows the simulated traffic about a minute after the first start."
