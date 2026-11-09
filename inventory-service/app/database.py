import os
import time
import logging
import psycopg

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("inventory-database")

DATABASE_URL = os.environ["DATABASE_URL"]


def get_connection():
    return psycopg.connect(
        DATABASE_URL,
        connect_timeout=5
    )


def initialize_database():
    statements = [
        """
        CREATE TABLE IF NOT EXISTS inventory_items (
            product_id BIGINT PRIMARY KEY,
            available_quantity BIGINT NOT NULL
                CHECK (available_quantity >= 0),
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        """,
        """
        CREATE TABLE IF NOT EXISTS reservations (
            reservation_id UUID PRIMARY KEY,
            payload_hash VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL
                CHECK (status IN ('reserved', 'released')),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        """,
        """
        CREATE TABLE IF NOT EXISTS reservation_items (
            id BIGSERIAL PRIMARY KEY,
            reservation_id UUID NOT NULL
                REFERENCES reservations(reservation_id),
            product_id BIGINT NOT NULL,
            quantity BIGINT NOT NULL CHECK (quantity > 0),
            UNIQUE (reservation_id, product_id)
        )
        """,
        """
        CREATE INDEX IF NOT EXISTS idx_reservation_items_reservation
        ON reservation_items(reservation_id)
        """
    ]

    for attempt in range(30):
        try:
            with get_connection() as connection:
                for statement in statements:
                    connection.execute(statement)

            logger.info("Inventory database initialized successfully.")
            return

        except psycopg.OperationalError:
            if attempt == 29:
                logger.exception("Could not connect to inventory database.")
                raise

            logger.warning(
                "Waiting for PostgreSQL... attempt %s/30",
                attempt + 1
            )
            time.sleep(2)