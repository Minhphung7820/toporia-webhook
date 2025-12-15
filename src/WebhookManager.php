<?php

declare(strict_types=1);

namespace Toporia\Webhook;

use Toporia\Webhook\Contracts\WebhookDispatcherInterface;
use Toporia\Webhook\Models\{WebhookEndpoint, WebhookDelivery};

/**
 * Class WebhookManager
 *
 * High-level manager for webhook operations.
 * Handles endpoint management, event dispatching, and delivery tracking.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebhookManager
{
    /**
     * @param WebhookDispatcherInterface $dispatcher Webhook dispatcher
     */
    public function __construct(
        private WebhookDispatcherInterface $dispatcher
    ) {}

    /**
     * Dispatch event to all matching endpoints.
     *
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param bool $async Dispatch asynchronously
     * @return array<string, bool> Map of endpoint URL => success status
     */
    public function dispatch(string $event, mixed $payload, bool $async = false): array
    {
        // Get active endpoints that should receive this event
        $endpoints = WebhookEndpoint::where('active', true)->get();

        $results = [];
        $endpointUrls = [];

        foreach ($endpoints as $endpoint) {
            if (!$endpoint->shouldReceive($event)) {
                continue;
            }

            $endpointUrls[] = $endpoint->url;

            $options = [
                'secret' => $endpoint->secret,
                'timeout' => $endpoint->timeout,
                'retry' => $endpoint->retry_count,
                'retry_delay' => $endpoint->retry_delay,
                'headers' => $endpoint->headers ?? [],
            ];

            if ($async) {
                $this->dispatcher->queue($event, $payload, $endpoint->url, $options);
                $results[$endpoint->url] = true; // Queued successfully
            } else {
                $success = $this->dispatcher->dispatchTo($event, $payload, $endpoint->url, $options);

                // Track delivery
                $this->trackDelivery($endpoint, $event, $payload, $success);

                $results[$endpoint->url] = $success;
            }
        }

        return $results;
    }

    /**
     * Track webhook delivery.
     *
     * @param WebhookEndpoint $endpoint Endpoint
     * @param string $event Event name
     * @param mixed $payload Payload
     * @param bool $success Success status
     * @return WebhookDelivery|null
     */
    private function trackDelivery(WebhookEndpoint $endpoint, string $event, mixed $payload, bool $success): ?WebhookDelivery
    {
        try {
            return WebhookDelivery::create([
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => is_array($payload) ? $payload : ['data' => $payload],
                'status_code' => $success ? 200 : null,
                'succeeded_at' => $success ? now()->toDateTimeString() : null,
                'attempts' => 1,
            ]);
        } catch (\Throwable $e) {
            // Log error but don't fail the webhook dispatch
            if (function_exists('log_error')) {
                log_error('Failed to track webhook delivery', [
                    'error' => $e->getMessage(),
                    'endpoint_id' => $endpoint->id,
                    'event' => $event,
                ]);
            }
            return null;
        }
    }
}

