<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
        ]);

        $order = DB::transaction(function () use ($request, $validated) {
            $total = 0;
            $orderLines = [];

            foreach ($validated['items'] as $item) {
                $product = Product::whereKey($item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['A selected product does not exist.'],
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$product->name}."],
                    ]);
                }

                $unitPrice = (float) $product->price;
                $total += $unitPrice * $item['quantity'];

                $orderLines[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                ];
            }

            $order = Order::create([
                'user_id' => $request->user('api')->id,
                'total' => $total,
                'status' => 'pending',
            ]);

            foreach ($orderLines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);

                $line['product']->decrement('stock', $line['quantity']);
            }

            return $order;
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load('items.product'),
        ], 201);
    }

    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user('api')->id) {
            return response()->json([
                'message' => 'Forbidden: You cannot access this order.',
            ], 403);
        }

        return response()->json($order->load('items.product'));
    }

    public function updateStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(['processing', 'shipped', 'delivered', 'cancelled']),
            ],
        ]);

        $allowedTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => [],
            'cancelled' => [],
        ];

        if (! in_array(
            $validated['status'],
            $allowedTransitions[$order->status] ?? [],
            true
        )) {
            return response()->json([
                'message' => 'Invalid order status transition.',
                'current_status' => $order->status,
                'requested_status' => $validated['status'],
            ], 422);
        }

        $order->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->fresh()->load('items.product'),
        ]);
    }
}
