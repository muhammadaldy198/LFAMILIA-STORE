<?php

/*
 * Database tes per worker paratest.
 *
 * Paratest menyetel TEST_TOKEN (1..N) per worker. Tanpa ini semua worker
 * memakai satu database dan saling menimpa migrasi — persis error 1412.
 *
 * Hanya berlaku saat TEST_TOKEN ada; mode sekuensial tetap memakai
 * DB_DATABASE dari phpunit.xml tanpa perubahan.
 */
$token = getenv('TEST_TOKEN');
if ($token === false || $token === '') {
    return;
}

$base = getenv('PARATEST_DB_BASE') ?: 'lfamilia_admin_redesign_test';
$database = $base.'_'.$token;

putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;
