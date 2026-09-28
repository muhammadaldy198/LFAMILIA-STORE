<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Throwable;

class D1SnapshotImporter
{
    public const CONFIRMATION = 'IMPORT_D1_TO_MARIADB';

    /** @var list<string> */
    private array $tables = [
        'customer_users',
        'customer_cleanup_settings',
        'customer_sessions',
        'customer_phone_otp_challenges',
        'customer_oauth_accounts',
        'customer_game_accounts',
        'customer_password_reset_tokens',
        'products',
        'product_packages',
        'product_notices',
        'orders',
        'order_fulfillment_units',
        'order_events',
        'voucher_codes',
        'voucher_deliveries',
        'store_settings',
        'wallet_settings',
        'wallet_topups',
        'wallet_transactions',
        'product_reviews',
        'home_banners',
        'site_popups',
        'news_articles',
        'product_categories',
        'discount_vouchers',
        'flash_sales',
        'promotion_reservations',
        'admin_users',
        'faq_entries',
        'customer_support_requests',
        'member_tier_settings',
        'payment_page_settings',
        'media_assets',
        'payment_channels',
        'payment_gateway_settings',
        'integration_profiles',
        'integration_settings',
        'security_rate_limits',
        'admin_activity_logs',
        'digiflazz_pricing_settings',
        'digiflazz_seller_monitor',
        'digiflazz_pricelist_cache',
        'digiflazz_pricelist_sync_state',
        'digiflazz_runtime_state',
        'one_time_operations',
    ];

    public function import(string $sourcePath, bool $replace, string $confirmation, callable $progress): array
    {
        if ($confirmation !== self::CONFIRMATION) {
            throw new RuntimeException('Confirmation phrase is missing or invalid.');
        }

        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("D1 production import requires a MariaDB/MySQL target; current driver is {$driver}.");
        }

        if (!$replace) {
            throw new RuntimeException('Use --replace for the controlled one-time production import.');
        }

        $sourcePath = realpath($sourcePath) ?: '';
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('D1 export file is not readable.');
        }

        [$source, $temporaryPath] = $this->openSource($sourcePath);

        try {
            $sourceTables = $this->sourceTables($source);
            $selected = array_values(array_filter(
                $this->tables,
                fn (string $table) => isset($sourceTables[$table]) && Schema::hasTable($table),
            ));

            if ($selected === []) {
                throw new RuntimeException('No recognized LFAMILIA tables were found in the D1 snapshot.');
            }

            $sourceCounts = [];
            foreach ($selected as $table) {
                $sourceCounts[$table] = (int) $source->query(
                    'SELECT COUNT(*) FROM '.$this->quoteSqliteIdentifier($table),
                )->fetchColumn();
            }

            $progress('Preflight OK: '.count($selected).' tables recognized.');

            $this->setForeignKeyChecks(false);
            DB::beginTransaction();

            try {
                foreach (array_reverse($selected) as $table) {
                    DB::table($table)->delete();
                }

                foreach ($selected as $table) {
                    $this->copyTable($source, $table, $progress);
                }

                DB::commit();
            } catch (Throwable $error) {
                DB::rollBack();
                throw $error;
            } finally {
                $this->setForeignKeyChecks(true);
            }

            $targetCounts = [];
            foreach ($selected as $table) {
                $targetCounts[$table] = (int) DB::table($table)->count();
                if ($targetCounts[$table] !== $sourceCounts[$table]) {
                    throw new RuntimeException(
                        "Row-count mismatch for {$table}: source={$sourceCounts[$table]} target={$targetCounts[$table]}",
                    );
                }
            }

            $financial = $this->financialReconciliation($source, $sourceTables);

            return [
                'tables' => $selected,
                'source_counts' => $sourceCounts,
                'target_counts' => $targetCounts,
                'financial' => $financial,
            ];
        } finally {
            $source = null;
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function openSource(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return [$pdo, null];
        }

        $temporaryPath = storage_path('app/d1-import-'.bin2hex(random_bytes(8)).'.sqlite');
        $pdo = new PDO('sqlite:'.$temporaryPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('D1 SQL export is empty.');
        }

        $pdo->exec($sql);

        return [$pdo, $temporaryPath];
    }

    private function sourceTables(PDO $source): array
    {
        $rows = $source->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('strval', $rows), true);
    }

    private function sourceColumns(PDO $source, string $table): array
    {
        $statement = $source->query('PRAGMA table_info('.$this->quoteSqliteIdentifier($table).')');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $row) => (string) $row['name'], $rows));
    }

    private function copyTable(PDO $source, string $table, callable $progress): void
    {
        $columns = $this->sourceColumns($source, $table);
        $targetColumns = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($columns, $targetColumns));

        if ($columns === []) {
            $progress("Skipped {$table}: no shared columns.");
            return;
        }

        $quotedColumns = implode(', ', array_map(
            fn (string $column) => $this->quoteSqliteIdentifier($column),
            $columns,
        ));

        $statement = $source->query(
            'SELECT '.$quotedColumns.' FROM '.$this->quoteSqliteIdentifier($table),
        );

        $copied = 0;
        $batch = [];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $batch[] = $this->normalizeRow($row);

            if (count($batch) >= 250) {
                DB::table($table)->insert($batch);
                $copied += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table($table)->insert($batch);
            $copied += count($batch);
        }

        $progress("Imported {$table}: {$copied} rows.");
    }

    private function normalizeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            if (!preg_match('/(?:_at|_until)$/', (string) $column)) {
                continue;
            }

            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}:\\d{2}/', $value)) {
                continue;
            }

            try {
                $date = new DateTimeImmutable($value);
                $row[$column] = $date
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Leave non-standard legacy values untouched so reconciliation
                // can surface an explicit target-side failure instead of hiding it.
            }
        }

        return $row;
    }

    private function financialReconciliation(PDO $source, array $sourceTables): array
    {
        $checks = [];

        if (isset($sourceTables['orders'])) {
            $sourcePaid = $source->query(
                "SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS total FROM orders WHERE payment_status='paid'",
            )->fetch(PDO::FETCH_ASSOC);

            $checks['paid_orders'] = [
                'source_count' => (int) ($sourcePaid['c'] ?? 0),
                'target_count' => (int) DB::table('orders')->where('payment_status', 'paid')->count(),
                'source_total' => (int) ($sourcePaid['total'] ?? 0),
                'target_total' => (int) DB::table('orders')->where('payment_status', 'paid')->sum('total'),
            ];
        }

        if (isset($sourceTables['wallet_transactions'])) {
            $sourceWallet = $source->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) AS credits,
                    COALESCE(SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END),0) AS debits
                 FROM wallet_transactions",
            )->fetch(PDO::FETCH_ASSOC);

            $checks['wallet_ledger'] = [
                'source_credits' => (int) ($sourceWallet['credits'] ?? 0),
                'target_credits' => (int) DB::table('wallet_transactions')->where('direction', 'credit')->sum('amount'),
                'source_debits' => (int) ($sourceWallet['debits'] ?? 0),
                'target_debits' => (int) DB::table('wallet_transactions')->where('direction', 'debit')->sum('amount'),
            ];
        }

        if (isset($sourceTables['customer_users'])) {
            $sourceBalances = (int) $source->query(
                'SELECT COALESCE(SUM(balance),0) FROM customer_users',
            )->fetchColumn();

            $checks['customer_balances'] = [
                'source_total' => $sourceBalances,
                'target_total' => (int) DB::table('customer_users')->sum('balance'),
            ];
        }

        foreach ($checks as $name => $values) {
            $sourceValues = array_filter($values, fn ($key) => str_starts_with((string) $key, 'source_'), ARRAY_FILTER_USE_KEY);
            foreach ($sourceValues as $key => $value) {
                $targetKey = 'target_'.substr($key, 7);
                if (array_key_exists($targetKey, $values) && $values[$targetKey] !== $value) {
                    throw new RuntimeException("Financial reconciliation failed: {$name}.{$key}");
                }
            }
        }

        return $checks;
    }

    private function setForeignKeyChecks(bool $enabled): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS='.(int) $enabled);
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
