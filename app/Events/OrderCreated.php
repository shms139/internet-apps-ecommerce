<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $orderId)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        $order = Order::with(['user', 'items'])
            ->findOrFail($this->orderId);

        return [
            'order' => [
                'id' => $order->id,
                'customer_name' => $order->user?->name,
                'total' => $order->total,
                'status' => $order->status,
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at?->toISOString(),
            ],
        ];
    }
}
