<?php

declare(strict_types=1);

namespace Toporia\Webhook;

use Toporia\Webhook\Contracts\{WebhookDispatcherInterface, SignatureGeneratorInterface};
use Toporia\Webhook\Jobs\DispatchWebhookJob;
use Toporia\Webhook\Models\{WebhookDelivery, WebhookFailure};
use Toporia\Framework\Http\Contracts\{HttpClientInterface, HttpResponseInterface};
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Log\Contracts\LoggerInterface;

/**
 * Class WebhookDispatcher
 *
 * Dispatches webhooks to external endpoints with retry, signature, and async support.
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
final class WebhookDispatcher implements WebhookDispatcherInterface
{
    /**
     * @param HttpClientInterface $httpClient HTTP client for sending requests
     * @param SignatureGeneratorInterface $signatureGenerator Signature generator
     * @param QueueManagerInterface|null $queue Queue manager for async dispatch
     * @param LoggerInterface|null $logger Logger for webhook events
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private SignatureGeneratorInterface $signatureGenerator,
        private ?QueueManagerInterface $queue = null,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function dispatch(string $event, mixed $payload, array $endpoints, array $options = []): array
    {
        $results = [];

        foreach ($endpoints as $endpoint) {
            $results[$endpoint] = $this->dispatchTo($event, $payload, $endpoint, $options);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchTo(string $event, mixed $payload, string $endpoint, array $options = []): bool
    {
        $secret = $options['secret'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $retry = $options['retry'] ?? 3; // Default 3 retries for robustness
        $headers = $options['headers'] ?? [];
        $method = $options['method'] ?? 'POST';
        $endpointId = $options['endpoint_id'] ?? null;

        // Generate idempotency key for deduplication
        $idempotencyKey = $this->generateIdempotencyKey($event, $payload, $endpoint);

        // Prepare payload
        $data = [
            'event' => $event,
            'timestamp' => now()->getTimestamp(),
            'data' => $payload,
            'idempotency_key' => $idempotencyKey,
        ];

        // Generate signature if secret provided
        if (!empty($secret)) {
            $signature = $this->signatureGenerator->generate($data, $secret);
            $headers['X-Webhook-Signature'] = $signature;
            $headers['X-Webhook-Signature-Algorithm'] = $this->signatureGenerator->getAlgorithm();
        }

        // Add event and idempotency headers
        $headers['X-Webhook-Event'] = $event;
        $headers['X-Webhook-Idempotency-Key'] = $idempotencyKey;
        $headers['Content-Type'] = 'application/json';

        // Prepare HTTP client
        $client = $this->httpClient
            ->withHeaders($headers)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();

        // Retry logic with exponential backoff
        $attempt = 0;
        $maxAttempts = $retry + 1;
        $lastError = null;
        $lastStatusCode = null;
        $lastResponseBody = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->sendRequest($client, $method, $endpoint, $data);
                $statusCode = $response->status();
                $responseBody = $response->body();

                if ($response->successful()) {
                    $this->log('info', "Webhook dispatched successfully", [
                        'event' => $event,
                        'endpoint' => $endpoint,
                        'status' => $statusCode,
                        'idempotency_key' => $idempotencyKey,
                        'attempts' => $attempt + 1,
                    ]);

                    // Track successful delivery if endpoint_id provided
                    if ($endpointId !== null) {
                        $this->trackDelivery($endpointId, $event, $payload, $statusCode, $responseBody, $attempt + 1, true, $idempotencyKey);
                    }

                    return true;
                }

                // Non-2xx response - store for potential DLQ
                $lastStatusCode = $statusCode;
                $lastResponseBody = $responseBody;
                $lastError = "HTTP {$statusCode}: {$responseBody}";

                $this->log('warning', "Webhook returned non-success status", [
                    'event' => $event,
                    'endpoint' => $endpoint,
                    'status' => $statusCode,
                    'body' => $responseBody,
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxAttempts - 1) {
                    $this->exponentialBackoff($attempt);
                    $attempt++;
                    continue;
                }

                // Final failure - send to DLQ
                $this->sendToDeadLetterQueue(
                    $endpointId,
                    $endpoint,
                    $event,
                    $payload,
                    $options,
                    $attempt + 1,
                    $lastError,
                    $lastStatusCode,
                    $lastResponseBody,
                    $idempotencyKey
                );

                return false;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                $this->log('error', "Webhook dispatch failed", [
                    'event' => $event,
                    'endpoint' => $endpoint,
                    'error' => $lastError,
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxAttempts - 1) {
                    $this->exponentialBackoff($attempt);
                    $attempt++;
                    continue;
                }

                // Final failure - send to DLQ
                $this->sendToDeadLetterQueue(
                    $endpointId,
                    $endpoint,
                    $event,
                    $payload,
                    $options,
                    $attempt + 1,
                    $lastError,
                    null,
                    null,
                    $idempotencyKey
                );

                return false;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function queue(string $event, mixed $payload, string $endpoint, array $options = []): void
    {
        if ($this->queue === null) {
            throw new \RuntimeException('Queue manager not available. Install queue service or dispatch synchronously.');
        }

        // Create job instance with proper constructor arguments
        $job = new DispatchWebhookJob(
            event: $event,
            payload: $payload,
            endpoint: $endpoint,
            options: $options
        );

        $queueName = $options['queue'] ?? 'webhooks';
        $this->queue->push($job, $queueName);
    }

    /**
     * Send HTTP request.
     *
     * @param HttpClientInterface $client HTTP client
     * @param string $method HTTP method
     * @param string $endpoint Target URL
     * @param array<string, mixed> $data Request data
     * @return HttpResponseInterface
     */
    private function sendRequest(HttpClientInterface $client, string $method, string $endpoint, array $data): HttpResponseInterface
    {
        return match (strtoupper($method)) {
            'GET' => $client->get($endpoint, $data),
            'POST' => $client->post($endpoint, $data),
            'PUT' => $client->put($endpoint, $data),
            'PATCH' => $client->patch($endpoint, $data),
            'DELETE' => $client->delete($endpoint),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    /**
     * Log webhook event.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Generate idempotency key for webhook delivery.
     *
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param string $endpoint Target endpoint
     * @return string Idempotency key (SHA-256 hash)
     */
    private function generateIdempotencyKey(string $event, mixed $payload, string $endpoint): string
    {
        $payloadString = is_string($payload) ? $payload : json_encode($payload);
        return hash('sha256', $event . $payloadString . $endpoint);
    }

    /**
     * Exponential backoff with jitter to prevent thundering herd.
     *
     * @param int $attempt Current attempt number (0-indexed)
     * @return void
     */
    private function exponentialBackoff(int $attempt): void
    {
        // Exponential backoff: 1s, 2s, 4s, 8s, 16s, ...
        // Max delay capped at 60 seconds
        $baseDelay = 1000; // 1 second in milliseconds
        $exponentialDelay = $baseDelay * (2 ** $attempt);
        $maxDelay = 60000; // 60 seconds

        // Add jitter (0-1000ms random) to prevent thundering herd
        $jitter = rand(0, 1000);
        $delay = min($exponentialDelay + $jitter, $maxDelay);

        // Convert to microseconds and sleep
        usleep($delay * 1000);
    }

    /**
     * Send failed webhook to Dead Letter Queue for manual review.
     *
     * @param int|null $endpointId Endpoint ID
     * @param string $endpoint Endpoint URL
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param array<string, mixed> $options Original options
     * @param int $totalAttempts Total attempts made
     * @param string $lastError Last error message
     * @param int|null $lastStatusCode Last HTTP status code
     * @param string|null $lastResponseBody Last response body
     * @param string $idempotencyKey Idempotency key
     * @return void
     */
    private function sendToDeadLetterQueue(
        ?int $endpointId,
        string $endpoint,
        string $event,
        mixed $payload,
        array $options,
        int $totalAttempts,
        string $lastError,
        ?int $lastStatusCode,
        ?string $lastResponseBody,
        string $idempotencyKey
    ): void {
        try {
            WebhookFailure::create([
                'endpoint_id' => $endpointId,
                'endpoint_url' => $endpoint,
                'event' => $event,
                'payload' => is_array($payload) ? $payload : ['data' => $payload],
                'options' => $options,
                'total_attempts' => $totalAttempts,
                'last_error_message' => $lastError,
                'last_status_code' => $lastStatusCode,
                'last_response_body' => $lastResponseBody,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->log('info', "Webhook failure stored in DLQ", [
                'event' => $event,
                'endpoint' => $endpoint,
                'idempotency_key' => $idempotencyKey,
                'attempts' => $totalAttempts,
            ]);
        } catch (\Throwable $e) {
            // If DLQ storage fails, log the error
            $this->log('critical', "Failed to store webhook in DLQ", [
                'event' => $event,
                'endpoint' => $endpoint,
                'dlq_error' => $e->getMessage(),
                'original_error' => $lastError,
            ]);
        }
    }

    /**
     * Track webhook delivery attempt.
     *
     * @param int $endpointId Endpoint ID
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param int $statusCode HTTP status code
     * @param string|null $responseBody Response body
     * @param int $attempts Number of attempts
     * @param bool $success Whether delivery succeeded
     * @param string $idempotencyKey Idempotency key
     * @return void
     */
    private function trackDelivery(
        int $endpointId,
        string $event,
        mixed $payload,
        int $statusCode,
        ?string $responseBody,
        int $attempts,
        bool $success,
        string $idempotencyKey
    ): void {
        try {
            $delivery = WebhookDelivery::create([
                'endpoint_id' => $endpointId,
                'event' => $event,
                'payload' => is_array($payload) ? $payload : ['data' => $payload],
                'status_code' => $statusCode,
                'response_body' => $responseBody,
                'attempts' => $attempts,
                'idempotency_key' => $idempotencyKey,
                'succeeded_at' => $success ? now()->toDateTimeString() : null,
                'failed_at' => !$success ? now()->toDateTimeString() : null,
            ]);
        } catch (\Throwable $e) {
            // Silently fail tracking - don't break webhook delivery
            $this->log('warning', "Failed to track webhook delivery", [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
