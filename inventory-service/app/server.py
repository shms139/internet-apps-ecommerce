import hashlib
import json
import logging
import os
import time
import uuid
from concurrent import futures

import grpc
import inventory_pb2
import inventory_pb2_grpc

from database import get_connection, initialize_database


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s"
)
logger = logging.getLogger("inventory-service")


class InventoryService(inventory_pb2_grpc.InventoryServiceServicer):

    def GetStock(self, request, context):
        try:
            with get_connection() as connection:
                row = connection.execute(
                    """
                    SELECT available_quantity
                    FROM inventory_items
                    WHERE product_id = %s
                    """,
                    (request.product_id,)
                ).fetchone()

            if row is None:
                return inventory_pb2.GetStockResponse(
                    found=False,
                    available_quantity=0
                )

            return inventory_pb2.GetStockResponse(
                found=True,
                available_quantity=row[0]
            )

        except Exception:
            logger.exception("GetStock failed")
            context.set_code(grpc.StatusCode.INTERNAL)
            context.set_details("Could not read inventory")
            return inventory_pb2.GetStockResponse()

    def SetStock(self, request, context):
        if request.product_id <= 0 or request.quantity < 0:
            return inventory_pb2.SetStockResponse(
                success=False,
                message="Invalid product ID or stock quantity"
            )

        try:
            with get_connection() as connection:
                connection.execute(
                    """
                    INSERT INTO inventory_items
                        (product_id, available_quantity, updated_at)
                    VALUES (%s, %s, CURRENT_TIMESTAMP)
                    ON CONFLICT (product_id)
                    DO UPDATE SET
                        available_quantity = EXCLUDED.available_quantity,
                        updated_at = CURRENT_TIMESTAMP
                    """,
                    (request.product_id, request.quantity)
                )

            return inventory_pb2.SetStockResponse(
                success=True,
                message="Stock updated successfully"
            )

        except Exception:
            logger.exception("SetStock failed")
            context.set_code(grpc.StatusCode.INTERNAL)
            context.set_details("Could not update inventory")
            return inventory_pb2.SetStockResponse(
                success=False,
                message="Internal inventory error"
            )

    def ReserveStock(self, request, context):
        try:
            reservation_id = str(uuid.UUID(request.reservation_id))

            quantities = {}
            for item in request.items:
                if item.product_id <= 0 or item.quantity <= 0:
                    return inventory_pb2.ReserveStockResponse(
                        success=False,
                        message="Invalid product ID or quantity"
                    )

                quantities[item.product_id] = (
                    quantities.get(item.product_id, 0) + item.quantity
                )

            if not quantities:
                return inventory_pb2.ReserveStockResponse(
                    success=False,
                    message="Reservation must contain at least one item"
                )

            items = sorted(quantities.items())
            payload = json.dumps(items, separators=(",", ":"))
            payload_hash = hashlib.sha256(
                payload.encode("utf-8")
            ).hexdigest()

            with get_connection() as connection:
                # Prevent two requests from processing the same reservation
                # simultaneously.
                connection.execute(
                    "SELECT pg_advisory_xact_lock(hashtextextended(%s, 0))",
                    (reservation_id,)
                )

                existing = connection.execute(
                    """
                    SELECT payload_hash, status
                    FROM reservations
                    WHERE reservation_id = %s
                    """,
                    (reservation_id,)
                ).fetchone()

                if existing:
                    if existing[0] != payload_hash:
                        return inventory_pb2.ReserveStockResponse(
                            success=False,
                            message="Reservation ID was already used with different items"
                        )

                    if existing[1] == "reserved":
                        return inventory_pb2.ReserveStockResponse(
                            success=True,
                            message="Reservation already exists"
                        )

                    return inventory_pb2.ReserveStockResponse(
                        success=False,
                        message="This reservation was already released"
                    )

                product_ids = [product_id for product_id, _ in items]

                rows = connection.execute(
                    """
                    SELECT product_id, available_quantity
                    FROM inventory_items
                    WHERE product_id = ANY(%s)
                    ORDER BY product_id
                    FOR UPDATE
                    """,
                    (product_ids,)
                ).fetchall()

                stock = {
                    product_id: available
                    for product_id, available in rows
                }

                # Validate every item before changing any stock.
                for product_id, quantity in items:
                    if product_id not in stock:
                        return inventory_pb2.ReserveStockResponse(
                            success=False,
                            message=f"Product {product_id} does not exist in inventory"
                        )

                    if stock[product_id] < quantity:
                        return inventory_pb2.ReserveStockResponse(
                            success=False,
                            message=f"Insufficient stock for product {product_id}"
                        )

                connection.execute(
                    """
                    INSERT INTO reservations
                        (reservation_id, payload_hash, status)
                    VALUES (%s, %s, 'reserved')
                    """,
                    (reservation_id, payload_hash)
                )

                for product_id, quantity in items:
                    connection.execute(
                        """
                        UPDATE inventory_items
                        SET available_quantity = available_quantity - %s,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE product_id = %s
                        """,
                        (quantity, product_id)
                    )

                    connection.execute(
                        """
                        INSERT INTO reservation_items
                            (reservation_id, product_id, quantity)
                        VALUES (%s, %s, %s)
                        """,
                        (reservation_id, product_id, quantity)
                    )

            return inventory_pb2.ReserveStockResponse(
                success=True,
                message="Stock reserved successfully"
            )

        except ValueError:
            return inventory_pb2.ReserveStockResponse(
                success=False,
                message="Invalid reservation UUID"
            )

        except Exception:
            logger.exception("ReserveStock failed")
            context.set_code(grpc.StatusCode.INTERNAL)
            context.set_details("Could not reserve inventory")
            return inventory_pb2.ReserveStockResponse(
                success=False,
                message="Internal inventory error"
            )

    def ReleaseStock(self, request, context):
        try:
            reservation_id = str(uuid.UUID(request.reservation_id))

            with get_connection() as connection:
                connection.execute(
                    "SELECT pg_advisory_xact_lock(hashtextextended(%s, 0))",
                    (reservation_id,)
                )

                reservation = connection.execute(
                    """
                    SELECT status
                    FROM reservations
                    WHERE reservation_id = %s
                    FOR UPDATE
                    """,
                    (reservation_id,)
                ).fetchone()

                if reservation is None:
                    return inventory_pb2.ReleaseStockResponse(
                        success=False,
                        message="Reservation not found"
                    )

                if reservation[0] == "released":
                    return inventory_pb2.ReleaseStockResponse(
                        success=True,
                        message="Reservation was already released"
                    )

                items = connection.execute(
                    """
                    SELECT product_id, quantity
                    FROM reservation_items
                    WHERE reservation_id = %s
                    ORDER BY product_id
                    """,
                    (reservation_id,)
                ).fetchall()

                for product_id, quantity in items:
                    connection.execute(
                        """
                        UPDATE inventory_items
                        SET available_quantity = available_quantity + %s,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE product_id = %s
                        """,
                        (quantity, product_id)
                    )

                connection.execute(
                    """
                    UPDATE reservations
                    SET status = 'released',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE reservation_id = %s
                    """,
                    (reservation_id,)
                )

            return inventory_pb2.ReleaseStockResponse(
                success=True,
                message="Stock released successfully"
            )

        except ValueError:
            return inventory_pb2.ReleaseStockResponse(
                success=False,
                message="Invalid reservation UUID"
            )

        except Exception:
            logger.exception("ReleaseStock failed")
            context.set_code(grpc.StatusCode.INTERNAL)
            context.set_details("Could not release inventory")
            return inventory_pb2.ReleaseStockResponse(
                success=False,
                message="Internal inventory error"
            )


def serve():
    initialize_database()

    server = grpc.server(
        futures.ThreadPoolExecutor(max_workers=10)
    )

    inventory_pb2_grpc.add_InventoryServiceServicer_to_server(
        InventoryService(),
        server
    )

    port = os.getenv("GRPC_PORT", "50051")
    server.add_insecure_port(f"[::]:{port}")
    server.start()

    logger.info("Inventory gRPC service is listening on port %s", port)
    server.wait_for_termination()


if __name__ == "__main__":
    serve()
