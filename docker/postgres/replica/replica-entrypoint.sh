#!/bin/sh
set -eu

if [ ! -s "$PGDATA/PG_VERSION" ]; then
    echo "Waiting for Primary PostgreSQL..."

    until pg_isready -h ecommerce-primary -p 5432; do
        sleep 2
    done

    echo "Creating initial replica copy..."

    install -d -o postgres -g postgres "$PGDATA"

    CONNECTION="host=ecommerce-primary port=5432 user=replicator password=${REPLICATION_PASSWORD}"

    gosu postgres pg_basebackup \
        -d "$CONNECTION" \
        -D "$PGDATA" \
        -Fp \
        -Xs \
        -P \
        -R
fi

exec docker-entrypoint.sh postgres
