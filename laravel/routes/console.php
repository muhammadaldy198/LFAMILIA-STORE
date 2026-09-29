<?php

use App\Services\D1SnapshotImporter;
use App\Services\MediaMigrationService;
use App\Services\ProductionReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('lfamilia:status', function () {
    $this->info('LFAMILIA Laravel migration runtime is available.');
})->purpose('Check the LFAMILIA Laravel runtime');

Artisan::command(
    'lfamilia:import-d1 {source : Path to a Cloudflare D1 .sql export or SQLite snapshot} {--replace} {--confirm=}',
    function (D1SnapshotImporter $importer) {
        $this->warn('This command replaces LFAMILIA domain data in the configured MariaDB database.');

        $result = $importer->import(
            (string) $this->argument('source'),
            (bool) $this->option('replace'),
            (string) $this->option('confirm'),
            fn (string $message) => $this->line($message),
        );

        $this->newLine();
        $this->info('D1 -> MariaDB import completed and reconciled.');
        $this->table(
            ['Check', 'Values'],
            collect($result['financial'])
                ->map(fn (array $values, string $name) => [$name, json_encode($values, JSON_UNESCAPED_SLASHES)])
                ->values()
                ->all(),
        );
    },
)->purpose('Import and reconcile a D1 production snapshot into MariaDB');


Artisan::command(
    'lfamilia:migrate-media {--source=https://lfamiliastore.my.id}',
    function (MediaMigrationService $service) {
        $result = $service->migrateReferenced(
            (string) $this->option('source'),
            fn (string $message) => $this->line($message),
        );

        $this->newLine();
        $this->info('Referenced media migration completed.');
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($result['failed'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    },
)->purpose('Copy referenced production media into the VPS media_assets table');


Artisan::command('lfamilia:reconcile', function (ProductionReconciliationService $service) {
    $result = $service->run();
    $this->info('LFAMILIA reconciliation completed.');
    $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
})->purpose('Reconcile payments, topups, promotions, and stale fulfillment');

Schedule::command('lfamilia:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:10')
    ->withoutOverlapping();

