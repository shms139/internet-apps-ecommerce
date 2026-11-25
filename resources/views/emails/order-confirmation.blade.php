<x-mail::message>
# Your order has been confirmed!

Hello {{ $order->user?->name ?? 'Customer' }},

Thank you for shopping with us.

We have successfully received your order **#{{ $order->id }}**.

<x-mail::table>
| Product | Quantity | Unit Price | Subtotal |
|:--------|----------:|-----------:|---------:|
@foreach ($order->items as $item)
| {{ $item->product?->name ?? ('Product #' . $item->product_id) }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->unit_price * $item->quantity, 2) }} |
@endforeach
</x-mail::table>

**Order Total:** {{ number_format((float) $order->total, 2) }}

**Order Status:** {{ ucfirst($order->status) }}

We appreciate your trust and hope to serve you again.

Thanks,
{{ config('app.name') }}
</x-mail::message>
