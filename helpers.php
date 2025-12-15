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
use Toporia\Webhook\Contracts\SignatureGeneratorInterface;

if (!function_exists('webhook')) {
    /**
     * Get the webhook manager instance.
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
     * Dispatch a webhook event to a single endpoint.
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param mixed $payload Event payload data
     * @param string $endpoint Target webhook URL
     * @param array<string, mixed> $options Additional options (secret, headers, timeout, retry, etc.)
     * @return bool Success status
     */
    function webhook_dispatch(string $event, mixed $payload, string $endpoint, array $options = []): bool
    {
        return app(WebhookDispatcherInterface::class)->dispatchTo($event, $payload, $endpoint, $options);
    }
}

if (!function_exists('webhook_dispatch_multiple')) {
    /**
     * Dispatch a webhook event to multiple endpoints.
     *
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param array<string, string> $endpoints Target webhook endpoints (name => url)
     * @param array<string, mixed> $options Additional options
     * @return array<string, bool> Map of endpoint => success status
     */
    function webhook_dispatch_multiple(string $event, mixed $payload, array $endpoints, array $options = []): array
    {
        return app(WebhookDispatcherInterface::class)->dispatch($event, $payload, $endpoints, $options);
    }
}

if (!function_exists('webhook_queue')) {
    /**
     * Queue a webhook for async dispatch.
     *
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param string $endpoint Target URL
     * @param array<string, mixed> $options Options
     * @return void
     */
    function webhook_queue(string $event, mixed $payload, string $endpoint, array $options = []): void
    {
        app(WebhookDispatcherInterface::class)->queue($event, $payload, $endpoint, $options);
    }
}

if (!function_exists('webhook_verify')) {
    /**
     * Verify an incoming webhook signature.
     *
     * @param array<string, mixed> $payload Webhook payload
     * @param string $signature Signature from header
     * @param string $secret Secret key for verification
     * @param string $algorithm Signature algorithm (default: sha256)
     * @return bool True if signature is valid
     */
    function webhook_verify(array $payload, string $signature, string $secret, string $algorithm = 'sha256'): bool
    {
        return app(SignatureGeneratorInterface::class)->verify($signature, $payload, $secret, $algorithm);
    }
}

if (!function_exists('webhook_generate_signature')) {
    /**
     * Generate a webhook signature.
     *
     * @param array<string, mixed> $payload Webhook payload
     * @param string $secret Secret key
     * @param string $algorithm Signature algorithm (default: sha256)
     * @return string Generated signature
     */
    function webhook_generate_signature(array $payload, string $secret, string $algorithm = 'sha256'): string
    {
        return app(SignatureGeneratorInterface::class)->generate($payload, $secret, $algorithm);
    }
}
