# rrdcached keeps RRD updates in memory and writes each file in one go,
# far less often than the poller updates it: this is what spares an SD card.
FROM alpine:3.20

RUN apk add --no-cache rrdtool-cached tini

COPY entrypoint.sh /usr/local/bin/rrdcached-start

EXPOSE 42217

ENTRYPOINT ["/sbin/tini", "--", "/bin/sh", "/usr/local/bin/rrdcached-start"]
