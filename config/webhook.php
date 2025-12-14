<?php

declare(strict_types=1);

/**
 * Webhook Configuration
 *
 * Configuration for webhook outbound and inbound functionality.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Signature Algorithm
    |--------------------------------------------------------------------------
    |
    | Algorithm used for generating webhook signatures.
    | Supported: sha256, sha1, sha512
    |
    */
    'signature_algorithm' => env('WEBHOOK_SIGNATURE_ALGORITHM', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | Default Options
    |--------------------------------------------------------------------------
    |
    | Default options for webhook dispatches.
    |
    */
    'defaults' => [
        'timeout' => env('WEBHOOK_TIMEOUT', 30), // seconds
        'retry' => env('WEBHOOK_RETRY', 3), // number of retries
        'retry_delay' => env('WEBHOOK_RETRY_DELAY', 1000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Secret key for verifying incoming webhooks.
    | Set this in your .env file: WEBHOOK_SECRET=your-secret-key
    |
    */
    'secret' => env('WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Queue configuration for async webhook dispatch.
    |
    */
    'queue' => [
        'enabled' => env('WEBHOOK_QUEUE_ENABLED', true),
        'queue_name' => env('WEBHOOK_QUEUE_NAME', 'webhooks'),
    ],
];
