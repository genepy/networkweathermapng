#!/bin/sh
# Replaces cron: polls every 5 minutes (the step of Observium's RRD files),
# discovers new devices at each round, everything every 6 hours, and runs the
# housekeeping once a day. Only errors are logged.
cd /opt/observium

round=0
while true; do
    started=$(date +%s)

    if [ $((round % 72)) -eq 0 ]; then
        php discovery.php -h all -q > /dev/null
    else
        php discovery.php -h new -q > /dev/null
    fi
    php poller.php -h all -q > /dev/null
    if [ "$round" -eq 0 ]; then
        # A device's first poll stores its counters as zero, so the next one
        # computes an absurd rate. Two quick extra polls get past that: the
        # map shows real traffic a minute after the start, not ten.
        for _ in 1 2; do
            sleep 20
            php poller.php -h all -q > /dev/null
        done
    fi
    if [ $((round % 288)) -eq 287 ]; then
        php housekeeping.php -ya > /dev/null
    fi

    round=$((round + 1))
    elapsed=$(( $(date +%s) - started ))
    [ "$elapsed" -lt 300 ] && sleep $((300 - elapsed))
done
