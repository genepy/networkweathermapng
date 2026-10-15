#!/bin/sh
# Builds the record file of $DEVICE (community "public") and starts the agent.
# The agent binds port 161 as root, then drops to "nobody".
set -e

lib=/usr/local/lib/snmpsim
python3 "$lib/make-snmprec.py" "$lib/devices.json" "$DEVICE" > /var/lib/snmpsim/data/public.snmprec

exec snmpsim-command-responder \
    --data-dir=/var/lib/snmpsim/data \
    --variation-modules-dir="$lib/variation" \
    --cache-dir=/var/cache/snmpsim \
    --agent-udpv4-endpoint=0.0.0.0:161 \
    --process-user=nobody --process-group=nobody
