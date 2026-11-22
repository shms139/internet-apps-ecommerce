<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PerformanceOrdersSeeder extends Seeder
{
    private const ORDER_COUNT = 200000;
    private const TEST_USER_COUNT = 100;
    private const BATCH_SIZE = 1000;
    private const INSERT_CHUNK_SIZE = 100;

    public function run(): void
    {
        $this->assertRequiredColumns(
            'users',
            ['name', 'email', 'password']
        );

        $this->assertRequiredColumns(
            'orders',
            ['id', 'user_id', 'total', 'status', 'created_at', 'updated_at']
        );

        $this->assertRequiredColumns(
            'order_items',
            ['order_id', 'product_id', 'quantity', 'unit_price']
        );

        $existing = DB::table('users')
            ->where('email', 'like', 'perf-seed-%@example.invalid')
            ->count();

        if ($existing > 0) {
            throw new RuntimeException(
                'Performance seed users already exist. Clean up the previous test dataset before running again.'
            );
        }

        $products = DB::table('products')
            ->select('id', 'price')
            ->orderBy('id')
            ->get()
            ->all();

        if (count($products) < 2) {
            throw new RuntimeException(
                'At least two products are required for performance seeding.'
            );
        }

        $userColumns = array_flip(
            Schema::getColumnListing('users')
        );

        $emails = [];
        $userRows = [];

        $createdAt = now()->format('Y-m-d H:i:s');
        $testPassword = Str::random(40);
        $passwordHash = Hash::make($testPassword);

        // Generate dedicated test users.
        for ($i = 1; $i <= self::TEST_USER_COUNT; $i++) {
            $email = sprintf(
                'perf-seed-%04d@example.invalid',
                $i
            );

            $emails[] = $email;

            $candidate = [
                'name' => "Performance Test User {$i}",
                'email' => $email,
                'password' => $passwordHash,
                'role' => 'customer',
                'email_verified_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $userRows[] = array_intersect_key(
                $candidate,
                $userColumns
            );
        }

        $userIds = [];

        try {
            // Insert users in small batches.
            DB::transaction(function () use ($userRows) {
                foreach (
                    array_chunk($userRows, 50) as $chunk
                ) {
                    DB::table('users')->insert($chunk);
                }
            });

            $userIds = DB::table('users')
                ->whereIn('email', $emails)
                ->orderBy('email')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($userIds) !== self::TEST_USER_COUNT) {
                throw new RuntimeException(
                    'Could not create all performance test users.'
                );
            }

            $orderColumns = array_flip(
                Schema::getColumnListing('orders')
            );

            $itemColumns = array_flip(
                Schema::getColumnListing('order_items')
            );

            $firstOrderId = (int) (
                DB::table('orders')->max('id') ?? 0
            );

            $productCount = count($products);

            $statuses = [
                'pending',
                'processing',
                'shipped',
                'delivered',
            ];

            $baseTimestamp = now()->timestamp;
            $inserted = 0;

            for (
                $offset = 0;
                $offset < self::ORDER_COUNT;
                $offset += self::BATCH_SIZE
            ) {
                $currentBatch = min(
                    self::BATCH_SIZE,
                    self::ORDER_COUNT - $offset
                );

                $orderRows = [];
                $itemRows = [];

                for ($j = 0; $j < $currentBatch; $j++) {
                    $sequence = $offset + $j + 1;

                    $orderId = $firstOrderId + $sequence;

                    $userId = $userIds[
                        ($sequence - 1) % count($userIds)
                    ];

                    // Select two different existing products.
                    $product1 = $products[
                        ($sequence - 1) % $productCount
                    ];

                    $product2 = $products[
                        $sequence % $productCount
                    ];

                    $quantity1 = ($sequence % 3) + 1;
                    $quantity2 = (intdiv($sequence, 3) % 3) + 1;

                    $price1 = (float) $product1->price;
                    $price2 = (float) $product2->price;

                    $total = round(
                        ($price1 * $quantity1) +
                        ($price2 * $quantity2),
                        2
                    );

                    $status = $statuses[
                        ($sequence - 1) % count($statuses)
                    ];

                    // Distribute timestamps across the previous year.
                    $timestamp = $baseTimestamp - (
                        ($sequence * 7919) % 31536000
                    );

                    $recordDate = date(
                        'Y-m-d H:i:s',
                        $timestamp
                    );

                    $orderCandidate = [
                        'id' => $orderId,
                        'user_id' => $userId,
                        'total' => number_format(
                            $total,
                            2,
                            '.',
                            ''
                        ),
                        'status' => $status,
                        'reservation_id' => null,
                        'created_at' => $recordDate,
                        'updated_at' => $recordDate,
                    ];

                    $orderRows[] = array_intersect_key(
                        $orderCandidate,
                        $orderColumns
                    );

                    $item1 = [
                        'order_id' => $orderId,
                        'product_id' => $product1->id,
                        'quantity' => $quantity1,
                        'unit_price' => number_format(
                            $price1,
                            2,
                            '.',
                            ''
                        ),
                        'created_at' => $recordDate,
                        'updated_at' => $recordDate,
                    ];

                    $item2 = [
                        'order_id' => $orderId,
                        'product_id' => $product2->id,
                        'quantity' => $quantity2,
                        'unit_price' => number_format(
                            $price2,
                            2,
                            '.',
                            ''
                        ),
                        'created_at' => $recordDate,
                        'updated_at' => $recordDate,
                    ];

                    $itemRows[] = array_intersect_key(
                        $item1,
                        $itemColumns
                    );

                    $itemRows[] = array_intersect_key(
                        $item2,
                        $itemColumns
                    );
                }

                // Each batch is atomic.
                DB::transaction(function () use (
                    $orderRows,
                    $itemRows
                ) {
                    foreach (
                        array_chunk(
                            $orderRows,
                            self::INSERT_CHUNK_SIZE
                        ) as $chunk
                    ) {
                        DB::table('orders')->insert($chunk);
                    }

                    foreach (
                        array_chunk(
                            $itemRows,
                            self::INSERT_CHUNK_SIZE
                        ) as $chunk
                    ) {
                        DB::table('order_items')->insert($chunk);
                    }
                });

                $inserted += $currentBatch;

                if (
                    $inserted % 10000 === 0 ||
                    $inserted === self::ORDER_COUNT
                ) {
                    $this->command?->info(
                        "Inserted {$inserted} / " .
                        self::ORDER_COUNT . " orders"
                    );
                }
            }

            $this->command?->info('Performance dataset created successfully.');
            $this->command?->info('Orders inserted: ' . self::ORDER_COUNT);
            $this->command?->info('Order items inserted: ' . (self::ORDER_COUNT * 2));
            $this->command?->info('Test users created: ' . self::TEST_USER_COUNT);
            $this->command?->warn('Test login: perf-seed-0001@example.invalid');
            $this->command?->warn('Temporary test password: ' . $testPassword);

        } catch (Throwable $exception) {
            // Remove the test dataset if the seeding process fails.
            $cleanupIds = $userIds ?: DB::table('users')
                ->whereIn('email', $emails)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($cleanupIds)) {
                $this->deleteTestData($cleanupIds);
            }

            throw $exception;
        }
    }

    private function assertRequiredColumns(
        string $table,
        array $required
    ): void {
        $actual = Schema::getColumnListing($table);
        $missing = array_diff($required, $actual);

        if (! empty($missing)) {
            throw new RuntimeException(
                "Missing columns in {$table}: " .
                implode(', ', $missing)
            );
        }
    }

    private function deleteTestData(array $userIds): void
    {
        DB::transaction(function () use ($userIds) {
            DB::table('order_items')
                ->whereIn('order_id', function ($query) use ($userIds) {
                    $query->select('id')
                        ->from('orders')
                        ->whereIn('user_id', $userIds);
                })
                ->delete();

            DB::table('orders')
                ->whereIn('user_id', $userIds)
                ->delete();

            DB::table('users')
                ->whereIn('id', $userIds)
                ->delete();
        });
    }
}