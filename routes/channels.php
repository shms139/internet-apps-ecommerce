<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Only the owner of an order can subscribe to its updates.
Broadcast::channel(
    'orders.{orderId}',
    function (User $user, int $orderId): bool {
        $order = Order::query()
            ->select(['id', 'user_id'])
            ->find($orderId);

        return $order !== null
            && (int) $order->user_id === (int) $user->id;
    },
    ['guards' => ['api']]
);

// Only administrators can subscribe to new-order notifications.
Broadcast::channel(
    'admin.orders',
    function (User $user): bool {
        return $user->role === 'admin';
    },
    ['guards' => ['api']]
);
