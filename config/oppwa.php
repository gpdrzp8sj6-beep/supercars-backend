<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OPPWA Payment Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OPPWA (Peach Payments) integration including
    | API credentials for both test and production environments.
    |
    */

    // Environment: 'test' or 'prod'
    'environment' => env('OPPWA_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Test Environment Configuration
    |--------------------------------------------------------------------------
    */
    'test' => [
        'base_url' => 'https://eu-test.oppwa.com',
        'entity_id' => '8ac7a4c7961768c301961b14272d05ed',
        'bearer_token' => 'OGFjN2E0Yzc5NjE3NjhjMzAxOTYxYjE0MjY1MDA1ZWJ8dz10WFVZcWgjYmN3IyU3azhZWFQ=',
        'webhook_key' => '92C4E99ED46AADE51D7C3C95348F8D6C7822C21212FFC0DB584B36BF1B5258DC',
        'webhook_endpoint' => 'https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook',
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Environment Configuration
    |--------------------------------------------------------------------------
    */
    'production' => [
        'base_url' => 'https://eu-prod.oppwa.com',
        'entity_id' => '8ac9a4cd9662a1bc0196687d626128ad',
        'bearer_token' => 'OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY=',
        'webhook_key' => env('OPPWA_PROD_WEBHOOK_KEY', 'E4666F48342B41B2FC9E3F989334E3DB2FED03B33A50D2783D8893366A88C663'), // Set this when you get production webhook key
        'webhook_endpoint' => 'https://api.jonnyfromdonnycompetitions.co.uk/v1/oppwa/webhook',
    ],

    /*
    |--------------------------------------------------------------------------
    | 3D Secure Testing Configuration
    |--------------------------------------------------------------------------
    */
    '3ds_testing' => [
        'enabled' => env('ENABLE_3DS_TEST_MODE', false),
        'flow' => env('3DS_TEST_FLOW', 'challenge'), // 'challenge' or 'frictionless'
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Configuration
    |--------------------------------------------------------------------------
    */
    'payment' => [
        'currency' => 'GBP',
        'payment_type' => 'DB', // Debit transaction
    ],
];