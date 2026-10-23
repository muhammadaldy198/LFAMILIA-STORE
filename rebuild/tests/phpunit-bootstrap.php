<?php

/*
 * Bootstrap PHPUnit/paratest.
 *
 * Urutan penting: bootstrap.php harus jalan SEBELUM Laravel membaca env,
 * jadi ia mengubah DB_DATABASE per worker lebih dulu, lalu autoloader
 * memuat framework seperti biasa.
 */
require __DIR__.'/bootstrap.php';

require __DIR__.'/../vendor/autoload.php';
