<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'reservation_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->uuid('reservation_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'reservation_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique(['reservation_id']);
                $table->dropColumn('reservation_id');
            });
        }
    }
};
