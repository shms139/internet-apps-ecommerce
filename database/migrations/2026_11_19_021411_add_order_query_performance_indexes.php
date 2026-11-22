<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Customer order history.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_orders_user_created_at
             ON orders (user_id, created_at DESC)'
        );

        // Admin order listing.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_orders_created_at
             ON orders (created_at DESC)'
        );

        // Loading items belonging to an order.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_order_items_order_id
             ON order_items (order_id)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS idx_orders_user_created_at'
        );

        DB::statement(
            'DROP INDEX IF EXISTS idx_orders_created_at'
        );

        DB::statement(
            'DROP INDEX IF EXISTS idx_order_items_order_id'
        );
    }
};