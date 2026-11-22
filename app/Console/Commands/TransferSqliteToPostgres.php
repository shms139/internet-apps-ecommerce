<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TransferSqliteToPostgres extends Command
{
    protected $signature = 'db:transfer-sqlite-to-pg {--chunk=1000}';

    protected $description = 'Safely transfer ecommerce data from SQLite to PostgreSQL';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1 || $chunkSize > 5000) {
            $this->error('Chunk size must be between 1 and 5000.');
            return self::FAILURE;
        }

        $source = DB::connection('sqlite');
        $target = DB::connection('pgsql');

        $tables = ['users', 'products', 'orders', 'order_items'];
        $sourceCounts = [];

        // Validate schemas and ensure PostgreSQL destination is empty.
        foreach ($tables as $table) {
            $sourceColumns = $source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);

            if ($sourceColumns !== $targetColumns) {
                $this->error("Column mismatch in table: {$table}");
                $this->line('SQLite: ' . implode(', ', $sourceColumns));
                $this->line('PostgreSQL: ' . implode(', ', $targetColumns));
                return self::FAILURE;
            }

            $sourceCounts[$table] = (int) $source->table($table)->count();
            $targetCount = (int) $target->table($table)->count();

            if ($targetCount !== 0) {
                $this->error("Destination table {$table} is not empty. Transfer cancelled.");
                return self::FAILURE;
            }

            $this->info("{$table}: {$sourceCounts[$table]} source rows; destination empty.");
        }

        try {
            // All destination inserts are committed together or rolled back.
            $target->transaction(function () use ($source, $target, $tables, $sourceCounts, $chunkSize) {

                // Parent tables must be copied before child tables.
                foreach ($tables as $table) {
                    $copied = 0;
                    $total = $sourceCounts[$table];

                    if ($total === 0) {
                        continue;
                    }

                    $source->table($table)->chunkById(
                        $chunkSize,
                        function ($rows) use ($target, $table, &$copied, $total) {
                            $previous = $copied;
                            $data = $rows->map(fn ($row) => (array) $row)->all();

                            $target->table($table)->insert($data);

                            $copied += count($data);

                            if (
                                intdiv($previous, 10000) !== intdiv($copied, 10000)
                                || $copied === $total
                            ) {
                                $this->line("{$table}: {$copied} / {$total}");
                            }
                        },
                        'id'
                    );

                    if ($copied !== $total) {
                        throw new RuntimeException("Incomplete transfer for {$table}");
                    }
                }

                // Reset PostgreSQL auto-increment sequences after preserving IDs.
                foreach ($tables as $table) {
                    $target->selectOne(
                        "SELECT setval(
                            pg_get_serial_sequence(?, 'id'),
                            COALESCE(MAX(id), 1),
                            COUNT(*) > 0
                        ) FROM {$table}",
                        [$table]
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->error('Transfer failed: ' . $exception->getMessage());
            $this->error('PostgreSQL transaction was rolled back.');
            return self::FAILURE;
        }

        // Verify counts after commit.
        foreach ($tables as $table) {
            $destinationCount = (int) $target->table($table)->count();

            if ($destinationCount !== $sourceCounts[$table]) {
                $this->error("Count mismatch after transfer in {$table}.");
                return self::FAILURE;
            }

            $this->info("Verified {$table}: {$destinationCount} rows.");
        }

        // Verify the reservation UUID was preserved exactly.
        $sourceReservation = $source->table('orders')
            ->where('id', 4)
            ->value('reservation_id');

        $targetReservation = $target->table('orders')
            ->where('id', 4)
            ->value('reservation_id');

        if ($sourceReservation !== $targetReservation) {
            $this->error('reservation_id verification failed for order 4.');
            return self::FAILURE;
        }

        $this->info('reservation_id verified successfully.');
        $this->info('SQLite to PostgreSQL transfer completed successfully.');

        return self::SUCCESS;
    }
}