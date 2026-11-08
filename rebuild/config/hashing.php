<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. The store standardised on Argon2id.
    | Existing bcrypt hashes keep verifying, because password verification
    | reads the algorithm embedded in the stored hash, and they are
    | transparently upgraded on the user's next login (see "rehash_on_login"
    | below), so no password reset is forced on anyone.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Bcrypt algorithm. This allows you to
    | control the amount of time it takes to hash the given password.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),

        // Algorithm verification stays off (the framework default) so a
        // hash written by the other algorithm remains verifiable: with
        // it on, the bcrypt hasher throws on Argon2id hashes instead of
        // checking them, which would lock out every already-upgraded
        // user if the driver were ever switched back to bcrypt.
        'verify' => env('HASH_VERIFY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Argon algorithm. These options allow you
    | to control the amount of time it takes to hash the given password.
    |
    | The values are PHP's own Argon2id defaults: 65536 KiB of memory (the
    | "memory" option is expressed in kibibytes), a time cost of 4 and a
    | single thread. One hash or verification takes roughly a fifth of a
    | second, and login throttling bounds how many can run concurrently on
    | the production VPS, so the 64 MiB per in-flight operation is safe.
    |
    | The "verify" option is deliberately NOT set here. Enabling it would
    | make the Argon2id hasher throw on any hash that is not already
    | Argon2id, which would lock every not-yet-migrated bcrypt user out of
    | the password-confirmation and current-password checks before their
    | next login could upgrade their hash.
    |
    */

    'argon' => [
        'memory' => env('HASH_MEMORY', 65536),
        'threads' => env('HASH_THREADS', 1),
        'time' => env('HASH_TIME', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, the user's password will be automatically
    | rehashed with the default driver whenever they log in, if their stored
    | hash no longer matches the current driver and options. This is what
    | migrates existing bcrypt password hashes to Argon2id organically, one
    | successful login at a time, on both the customer and admin guards.
    |
    */

    'rehash_on_login' => true,

];
