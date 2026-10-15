# Small SNMP agent that simulates one network device of devices.json
FROM python:3.12-alpine

RUN pip install --no-cache-dir snmpsim==1.2.2 pysmi==2.0.0 \
    && mkdir -p /var/lib/snmpsim/data /var/cache/snmpsim \
    && chown nobody:nobody /var/lib/snmpsim/data /var/cache/snmpsim

COPY variation/ /usr/local/lib/snmpsim/variation/
COPY devices.json make-snmprec.py entrypoint.sh /usr/local/lib/snmpsim/

ENV DEVICE=core-router

EXPOSE 161/udp

ENTRYPOINT ["/bin/sh", "/usr/local/lib/snmpsim/entrypoint.sh"]
