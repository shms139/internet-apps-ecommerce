<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $timeout = 90;

    public function __construct(public int $orderId)
    {
        $this->onConnection('rabbitmq');
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $order = Order::with(['user', 'items.product'])
            ->find($this->orderId);

        if (! $order) {
            Log::warning('Order confirmation email skipped: order not found.', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $email = $order->user?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Order confirmation email skipped: invalid recipient.', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        Mail::to($email)->send(new OrderConfirmationMail($order));

        Log::info('Order confirmation email processed successfully.', [
            'order_id' => $order->id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Order confirmation email job failed permanently.', [
            'order_id' => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
