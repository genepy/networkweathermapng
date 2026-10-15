#!/bin/sh
# Starts nginx in the foreground. The IPv6 listener is only added when the
# container has IPv6: on hosts where it is disabled, "listen [::]:80" makes
# nginx refuse to start.
set -e

conf=/etc/nginx/ipv6-listen.conf
if [ -e /proc/net/if_inet6 ]; then
    echo 'listen [::]:80 default_server;' > "$conf"
else
    rm -f "$conf"
fi

exec /usr/sbin/nginx -g 'daemon off;'
