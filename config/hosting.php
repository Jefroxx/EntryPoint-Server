<?php

/** Settings that only matter when the system is hosted. They are read by `php artisan entrypoint:setup`. */
return [
    // Fill an empty database with the sample data (demos only: the sample accounts share a known password).
    'seed_demo' => filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN),

    // A word of your choice: the first start that sees a NEW word wipes the database and loads the demo data, once.
    'reset_demo' => env('RESET_DEMO_DATA'),

    // The first administrator, created once if no account has this email.
    'admin_email'      => env('ADMIN_EMAIL'),
    'admin_password'   => env('ADMIN_PASSWORD'),
    'admin_first_name' => env('ADMIN_FIRST_NAME', 'System'),
    'admin_last_name'  => env('ADMIN_LAST_NAME', 'Administrator'),
];
