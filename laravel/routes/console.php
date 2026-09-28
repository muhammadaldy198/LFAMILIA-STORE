<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('lfamilia:status', function () {
    $this->info('LFAMILIA Laravel migration runtime is available.');
})->purpose('Check the LFAMILIA Laravel runtime');
