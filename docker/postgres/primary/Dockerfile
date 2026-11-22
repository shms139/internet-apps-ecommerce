FROM postgres:16-alpine

COPY 01-replication.sh /docker-entrypoint-initdb.d/01-replication.sh

RUN sed -i 's/\r$//' /docker-entrypoint-initdb.d/01-replication.sh \
    && chmod +x /docker-entrypoint-initdb.d/01-replication.sh
