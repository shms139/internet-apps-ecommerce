<?php

namespace App\Http\Controllers\Api\v1;
use Illuminate\Routing\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\InventoryGrpcClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;
use App\Jobs\SendOrderConfirmationEmail;
use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user('api')->id)
            ->with('items.product')
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function adminIndex(Request $request)
    {
        $orders = Order::with(['user', 'items.product'])
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function store(Request $request, InventoryGrpcClient $inventory)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
        ]);

        $products = Product::whereIn(
            'id',
            collect($validated['items'])->pluck('product_id')
        )->get()->keyBy('id');

        if ($products->count() !== count($validated['items'])) {
            return response()->json([
                'message' => 'One or more products no longer exist.',
            ], 422);
        }

        $total = 0;
        $orderLines = [];
        $reservationItems = [];

        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $product->price;

            $total += $unitPrice * $quantity;

            $orderLines[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];

            $reservationItems[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ];
        }

        $reservationId = (string) Str::uuid();

        // Reserve inventory before creating the order.
        try {
            $reservation = $inventory->reserveStock(
                $reservationId,
                $reservationItems
            );
        } catch (Throwable $exception) {
            // The reservation outcome may be unknown after a timeout.
            $this->releaseSafely($inventory, $reservationId);

            Log::error('Inventory reservation communication failed.', [
                'reservation_id' => $reservationId,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Inventory service is unavailable. Please retry.',
                'reservation_id' => $reservationId,
            ], 503);
        }

        if (! $reservation['success']) {
            return response()->json([
                'message' => 'Unable to reserve inventory.',
                'reason' => $reservation['message'],
            ], 409);
        }

        // Create the order only after inventory reservation succeeds.
        try {
            $order = DB::transaction(function () use (
                $request,
                $total,
                $orderLines,
                $reservationId
            ) {
                $order = Order::create([
                    'user_id' => $request->user('api')->id,
                    'total' => round($total, 2),
                    'status' => 'pending',
                    'reservation_id' => $reservationId,
                ]);

                foreach ($orderLines as $line) {
                    $order->items()->create($line);
                }

                return $order;
            });
        } catch (Throwable $exception) {
            // Compensate: release inventory if local order creation fails.
            $released = $this->releaseSafely($inventory, $reservationId);

            Log::error('Order creation failed after inventory reservation.', [
                'reservation_id' => $reservationId,
                'inventory_released' => $released,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Order creation failed. Inventory release was attempted.',
                'reservation_id' => $reservationId,
                'inventory_released' => $released,
            ], 500);
        }

        // Queue order confirmation email after successful order creation.
        $emailQueued = false;

        try {
            SendOrderConfirmationEmail::dispatch((int) $order->id);
            $emailQueued = true;
        } catch (Throwable $exception) {
            Log::error('Failed to queue order confirmation email.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        // Notify administrators about the newly created order.
        try {
            event(new OrderCreated((int) $order->id));
        } catch (Throwable $exception) {
            Log::error('Failed to broadcast new order event.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load('items.product'),
            'email_queued' => $emailQueued,
        ], 201);

    }
    private function releaseSafely(
        InventoryGrpcClient $inventory,
        string $reservationId
    ): bool {
        try {
            $result = $inventory->releaseStock($reservationId);

            return (bool) $result['success'];
        } catch (Throwable $exception) {
            Log::critical('Inventory reservation release failed.', [
                'reservation_id' => $reservationId,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function show(Request $request, Order $order)
    {
        $user = $request->user('api');

        if ($user->role !== 'admin' && $order->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden: You cannot access this order.',
            ], 403);
        }

        return response()->json(
            $order->load(['user', 'items.product'])
        );
    }


    public function updateStatus(
        Request $request,
        Order $order,
        InventoryGrpcClient $inventory
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(['processing', 'shipped', 'delivered', 'cancelled']),
            ],
        ]);

        $newStatus = $validated['status'];
        $currentStatus = $order->status;

        $allowedTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => [],
            'cancelled' => [],
        ];

        // Allow retrying inventory release for an already cancelled order.
        $isCancellationRetry =
            $currentStatus === 'cancelled' &&
            $newStatus === 'cancelled';

        if (
            ! $isCancellationRetry &&
            ! in_array(
                $newStatus,
                $allowedTransitions[$currentStatus] ?? [],
                true
            )
        ) {
            return response()->json([
                'message' => 'Invalid order status transition.',
                'current_status' => $currentStatus,
                'requested_status' => $newStatus,
            ], 422);
        }

        // Update the local order status first.
               if (! $isCancellationRetry) {
            $order->update([
                'status' => $newStatus,
            ]);

            // Notify the order owner about the status change.
            try {
                event(new OrderStatusUpdated((int) $order->id));
            } catch (Throwable $exception) {
                Log::error('Failed to broadcast order status event.', [
                    'order_id' => $order->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        // Release reserved inventory when the order is cancelled.
        if ($newStatus === 'cancelled' && $order->reservation_id) {
            $released = $this->releaseSafely(
                $inventory,
                $order->reservation_id
            );

            if (! $released) {
                Log::critical('Order cancelled but inventory release is pending.', [
                    'order_id' => $order->id,
                    'reservation_id' => $order->reservation_id,
                ]);

                return response()->json([
                    'message' => 'Order is cancelled, but inventory release failed. Retry the same cancellation request.',
                    'order_status' => 'cancelled',
                    'reservation_id' => $order->reservation_id,
                    'inventory_released' => false,
                ], 503);
            }
        }

        return response()->json([
            'message' => $isCancellationRetry
                ? 'Inventory release retry completed.'
                : 'Order status updated successfully.',
            'order' => $order->fresh()->load('items.product'),
            'inventory_released' => $newStatus === 'cancelled'
                ? (bool) $order->reservation_id
                : null,
        ]);
    }
}
