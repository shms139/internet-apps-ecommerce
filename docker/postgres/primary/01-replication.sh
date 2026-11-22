#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set=replication_password="$REPLICATION_PASSWORD" <<'SQL'
CREATE ROLE replicator
WITH REPLICATION LOGIN PASSWORD :'replication_password';
SQL

echo "host replication replicator 0.0.0.0/0 scram-sha-256" >> "$PGDATA/pg_hba.conf"
