<?php

declare(strict_types=1);

/**
 * Webhook Routes
 *
 * Routes for webhook functionality (inbound webhooks).
 */

use Toporia\Framework\Support\Accessors\Route;
use Toporia\Webhook\Controllers\WebhookController;

// Inbound webhook endpoint
// POST /webhook/{provider} or POST /webhook
Route::post('/webhook', [WebhookController::class, 'handle']);
Route::post('/webhook/{provider}', [WebhookController::class, 'handle'])
    ->where('provider', '[a-z0-9_-]+');
