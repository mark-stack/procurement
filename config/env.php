<?php

return [
    'admin_email' => env('ADMIN_EMAIL', 'mark.laravel.coder@gmail.com'),
    /*
     * Test mode compresses every notification window from days to minutes, so the hourly job and
     * the trial reminders run on a schedule of everyMinute and the quarterly offcut cleanout does
     * too - see routes/console.php.
     *
     * It defaulted to true. A deployment that had not thought about TEST_MODE therefore mailed
     * every customer's project managers once a minute, which is the loudest possible way to find
     * out about a missing line in .env. Local development asks for it explicitly instead.
     */
    'test_mode' => env('TEST_MODE', false),
    'admin_business' => env('ADMIN_BUSINESS'),
    "nesting_iterations" => env("NESTING_ITERATIONS",1000),
];
