#!/bin/sh
# Prepares /app/data before PHP starts.
#
# Icons and backgrounds live in the data volume, so that images added in the
# editor or by a migration survive a new container. The images shipped with
# the application are copied there without replacing what is already there.
set -e

mkdir -p /app/data /app/logs
for dir in objects backgrounds; do
    mkdir -p "/app/data/$dir"
    if [ -d "/app/public/$dir.dist" ]; then
        (
            cd "/app/public/$dir.dist"
            find . -type d -exec mkdir -p "/app/data/$dir/{}" \;
            find . -type f | while IFS= read -r f; do
                [ -e "/app/data/$dir/$f" ] || cp -p "$f" "/app/data/$dir/$f"
            done
        )
    fi
done

chown -R www-data:www-data /app/data /app/logs
chmod -R 775 /app/data /app/logs
