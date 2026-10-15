#!/bin/sh
# RRDCACHED_WRITE_DELAY  seconds an update may stay in memory (default 1800)
# RRDCACHED_WRITE_JITTER random extra delay, spreads the writes (default 900)
# RRDCACHED_JOURNAL      "on" keeps a journal so a crash loses nothing, at the
#                        cost of a small write for every update (default off)
#
# On "docker stop" rrdcached writes everything still in memory: give it time
# to finish (stop_grace_period in docker-compose.yml).
set -e

base=/opt/observium/rrd
mkdir -p "$base"

set -- -g \
    -l 0.0.0.0:42217 \
    -b "$base" -B -R \
    -w "${RRDCACHED_WRITE_DELAY:-1800}" \
    -z "${RRDCACHED_WRITE_JITTER:-900}" \
    -f "$(( ${RRDCACHED_WRITE_DELAY:-1800} * 2 ))" \
    -t 2

if [ "${RRDCACHED_JOURNAL:-off}" = "on" ]; then
    mkdir -p /var/lib/rrdcached/journal
    set -- "$@" -j /var/lib/rrdcached/journal
fi

exec rrdcached "$@"
