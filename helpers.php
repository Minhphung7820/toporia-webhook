<?php

declare(strict_types=1);

/**
 * Webhook Helper Functions
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */

use Toporia\Webhook\WebhookManager;
use Toporia\Webhook\Contracts\WebhookDispatcherInterface;
use Toporia\Webhook\Contracts\WebhookReceiverInterface;

if (!function_exists('webhook')) {
    /**
     * Get the webhook manager.
     *
     * @return WebhookManager
     */
    function webhook(): WebhookManager
    {
        return app(WebhookManager::class);
    }
}

if (!function_exists('webhook_dispatch')) {
    /**
     * Dispatch a webhook to a URL.
     *
     * @param string $url
     * @param array $payload
     * @param string|null $secret
     * @param array $options
     * @return array Response data
     */
    function webhook_dispatch(string $url, array $payload, ?string $secret = null, array $options = []): array
    {
        return app(WebhookDispatcherInterface::class)->dispatch($url, $payload, $secret, $options);
    }
}

if (!function_exists('webhook_verify')) {
    /**
     * Verify an incoming webhook signature.
     *
     * @param string $payload
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    function webhook_verify(string $payload, string $signature, string $secret): bool
    {
        return app(WebhookReceiverInterface::class)->verify($payload, $signature, $secret);
    }
}
