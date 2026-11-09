<?php

namespace App\Services;

use Grpc\ChannelCredentials;
use Inventory\GetStockRequest;
use Inventory\InventoryServiceClient;
use Inventory\ReleaseStockRequest;
use Inventory\ReserveStockRequest;
use Inventory\SetStockRequest;
use Inventory\StockItem;
use Illuminate\Support\Str;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class InventoryGrpcClient
{
    private InventoryServiceClient $client;

    private int $timeout;

    public function __construct()
    {
        $host = config(
            'inventory.grpc_host',
            'inventory-service:50051'
        );

        $timeoutMilliseconds = (int) config(
            'inventory.grpc_timeout',
            3000
        );

        $this->timeout = $timeoutMilliseconds * 1000;

        $this->client = new InventoryServiceClient(
            $host,
            [
                'credentials' => ChannelCredentials::createInsecure(),
            ]
        );
    }

    /**
     * Read available stock for a product.
     */
    public function getStock(int $productId): array
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException(
                'Product ID must be greater than zero.'
            );
        }

        $request = new GetStockRequest();
        $request->setProductId($productId);

        $response = $this->await(
            $this->client->GetStock(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'found' => $response->getFound(),
            'available_quantity' => (int) $response->getAvailableQuantity(),
        ];
    }

    /**
     * Set the available stock for a product.
     */
    public function setStock(int $productId, int $quantity): array
    {
        if ($productId <= 0 || $quantity < 0) {
            throw new InvalidArgumentException(
                'Invalid product ID or stock quantity.'
            );
        }

        $request = new SetStockRequest();
        $request->setProductId($productId);
        $request->setQuantity($quantity);

        $response = $this->await(
            $this->client->SetStock(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'success' => $response->getSuccess(),
            'message' => $response->getMessage(),
        ];
    }

    /**
     * Reserve multiple products atomically.
     *
     * Expected items:
     * [
     *     ['product_id' => 1, 'quantity' => 2],
     *     ['product_id' => 2, 'quantity' => 1],
     * ]
     */
    public function reserveStock(
        string $reservationId,
        array $items
    ): array {
        if (!Str::isUuid($reservationId)) {
            throw new InvalidArgumentException(
                'Reservation ID must be a valid UUID.'
            );
        }

        if (empty($items)) {
            throw new InvalidArgumentException(
                'Reservation must contain at least one item.'
            );
        }

        $stockItems = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new InvalidArgumentException(
                    'Each reservation item must have a valid product ID and quantity.'
                );
            }

            $stockItem = new StockItem();
            $stockItem->setProductId($productId);
            $stockItem->setQuantity($quantity);

            $stockItems[] = $stockItem;
        }

        $request = new ReserveStockRequest();
        $request->setReservationId($reservationId);
        $request->setItems($stockItems);

        $response = $this->await(
            $this->client->ReserveStock(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'success' => $response->getSuccess(),
            'message' => $response->getMessage(),
        ];
    }

    /**
     * Release a previously reserved stock reservation.
     */
    public function releaseStock(string $reservationId): array
    {
        if (!Str::isUuid($reservationId)) {
            throw new InvalidArgumentException(
                'Reservation ID must be a valid UUID.'
            );
        }

        $request = new ReleaseStockRequest();
        $request->setReservationId($reservationId);

        $response = $this->await(
            $this->client->ReleaseStock(
                $request,
                [],
                ['timeout' => $this->timeout]
            )
        );

        return [
            'success' => $response->getSuccess(),
            'message' => $response->getMessage(),
        ];
    }

    /**
     * Wait for a gRPC response and handle transport errors.
     */
    private function await($call)
    {
        try {
            [$response, $status] = $call->wait();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Inventory gRPC communication failed: ' .
                $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException(
                sprintf(
                    'Inventory gRPC error [%s]: %s',
                    $status->code,
                    $status->details
                )
            );
        }

        return $response;
    }
}
