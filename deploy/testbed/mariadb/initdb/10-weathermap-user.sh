#!/bin/sh
# Read-only account through which Weathermap reads Observium's database.
# Runs once, when the database volume is created.
set -e

mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'weathermap'@'%' IDENTIFIED BY '${WEATHERMAP_DB_PASSWORD}';
GRANT SELECT ON \`${MARIADB_DATABASE}\`.* TO 'weathermap'@'%';
SQL
