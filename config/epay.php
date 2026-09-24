<?php

declare(strict_types=1);

/*
 * ePay Business API configuration.
 *
 * Publish this file with:
 *   php artisan vendor:publish --tag=epay-config
 */

return [
    /*
     * Your secret key. The prefix selects the environment: sk_test_… hits the
     * sandbox and sk_live_… moves real money.
     */
    'api_key' => env('EPAY_SECRET_KEY'),

    /*
     * Webhook signing secret, from Developers -> Webhooks. Required to use the
     * VerifyEpayWebhook middleware.
     */
    'webhook_secret' => env('EPAY_WEBHOOK_SECRET'),

    /*
     * API root. Leave unset unless ePay has given you a different endpoint.
     */
    'base_url' => env('EPAY_BASE_URL'),

    /*
     * Per-attempt timeout, in seconds.
     */
    'timeout' => env('EPAY_TIMEOUT', 30),

    /*
     * Retries after the first attempt, for 429/5xx/network failures.
     */
    'max_retries' => env('EPAY_MAX_RETRIES', 2),
];
