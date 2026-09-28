<?php

use App\Services\D1SnapshotImporter;
use Illuminate\Support\Facades\Artisan;

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
