FROM postgres:16-alpine

COPY replica-entrypoint.sh /usr/local/bin/replica-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/replica-entrypoint.sh \
    && chmod +x /usr/local/bin/replica-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/replica-entrypoint.sh"]
