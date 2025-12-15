# Toporia Webhook Package

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Framework](https://img.shields.io/badge/framework-Toporia-orange.svg)](https://github.com/Minhphung7820/toporia)

A comprehensive webhook dispatching and receiving package for Toporia Framework with enterprise-grade reliability, security, and observability.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Outbound Webhooks](#outbound-webhooks)
- [Inbound Webhooks](#inbound-webhooks)
- [Security](#security)
- [Reliability Features](#reliability-features)
- [Database Schema](#database-schema)
- [Code Examples](#code-examples)
- [Troubleshooting](#troubleshooting)
- [Performance](#performance)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features

### Outbound Webhooks
- **Single & Multi-Endpoint Dispatch**: Send webhooks to one or multiple endpoints
- **Async Queue Support**: Queue-based dispatch for non-blocking operations
- **Custom Headers & Options**: Full control over HTTP requests
- **Event Filtering**: Wildcard pattern matching for event subscriptions
- **Delivery Tracking**: Complete audit trail of webhook deliveries

### Inbound Webhooks
- **Signature Verification**: HMAC-based signature validation (SHA256/SHA1/SHA512)
- **Replay Attack Protection**: Timestamp validation prevents replay attacks
- **Automatic Event Extraction**: Parse events from headers or payload
- **Flexible Handler System**: Custom callbacks for event processing

### Security
- **HMAC Signature Generation**: Secure webhook signatures using HMAC
- **Timing-Safe Comparisons**: Prevent timing attacks with `hash_equals()`
- **Replay Protection**: 5-minute timestamp window prevents replay attacks
- **Open Redirect Prevention**: Validate redirect URLs

### Reliability
- **Exponential Backoff with Jitter**: Smart retry delays (1s, 2s, 4s, 8s...)
- **Dead Letter Queue (DLQ)**: Failed webhooks stored for manual review
- **Idempotency Keys**: SHA-256 hashes prevent duplicate processing
- **Configurable Retries**: Up to 3 retries by default (configurable)

## Requirements

- **PHP**: >= 8.1
- **Toporia Framework**: ^1.0
- **Extensions**:
  - `ext-json` (required)
  - `ext-hash` (required)
  - `ext-redis` (optional, for queue support)

## Installation

### 1. Install via Composer

```bash
composer require toporia/webhook
```

### 2. Publish Configuration

```bash
php console vendor:publish --provider="Toporia\Webhook\WebhookServiceProvider"
```

This publishes `config/webhook.php` to your application.

### 3. Run Migrations

```bash
php console migrate
```

This creates:
- `webhook_endpoints` - Webhook endpoint configurations
- `webhook_deliveries` - Delivery tracking and audit log
- `webhook_failures` - Dead Letter Queue for failed webhooks

### 4. Configure Environment

Add to your `.env` file:

```env
WEBHOOK_SIGNATURE_ALGORITHM=sha256
WEBHOOK_TIMEOUT=30
WEBHOOK_RETRY=3
WEBHOOK_RETRY_DELAY=1000
WEBHOOK_SECRET=your-secret-key-here
WEBHOOK_QUEUE_ENABLED=true
WEBHOOK_QUEUE_NAME=webhooks
```

## Quick Start

### Sending a Webhook (Outbound)

```php
use Toporia\Webhook\Contracts\WebhookDispatcherInterface;

$dispatcher = app(WebhookDispatcherInterface::class);

// Dispatch webhook synchronously
$success = $dispatcher->dispatchTo(
    event: 'user.created',
    payload: ['user_id' => 123, 'email' => 'user@example.com'],
    endpoint: 'https://example.com/webhook',
    options: [
        'secret' => 'webhook-secret',
        'timeout' => 30,
        'retry' => 3,
    ]
);
```

### Receiving a Webhook (Inbound)

```php
use Toporia\Webhook\Contracts\WebhookReceiverInterface;
use Toporia\Framework\Http\Request;

$receiver = app(WebhookReceiverInterface::class);

try {
    $data = $receiver->process($request, 'webhook-secret', function ($event, $payload, $request) {
        match ($event) {
            'payment.completed' => $this->handlePayment($payload),
            'order.created' => $this->handleOrder($payload),
            default => logger()->info("Unknown event: {$event}"),
        };
    });

    return response()->json(['success' => true], 200);
} catch (\RuntimeException $e) {
    return response()->json(['error' => 'Invalid signature'], 401);
}
```

## Configuration

### Configuration File (`config/webhook.php`)

```php
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
```

## Outbound Webhooks

### Single Endpoint Dispatch

```php
use Toporia\Webhook\Contracts\WebhookDispatcherInterface;

$dispatcher = app(WebhookDispatcherInterface::class);

$success = $dispatcher->dispatchTo(
    event: 'order.created',
    payload: [
        'order_id' => 456,
        'total' => 99.99,
        'items' => ['product_1', 'product_2'],
    ],
    endpoint: 'https://api.example.com/webhooks',
    options: [
        'secret' => 'your-secret-key',
        'timeout' => 30,
        'retry' => 3,
        'retry_delay' => 1000,
        'method' => 'POST',
        'headers' => [
            'X-Custom-Header' => 'custom-value',
            'X-API-Key' => 'api-key',
        ],
    ]
);

if ($success) {
    echo "Webhook delivered successfully!";
} else {
    echo "Webhook delivery failed. Check DLQ for details.";
}
```

### Multi-Endpoint Dispatch

```php
$results = $dispatcher->dispatch(
    event: 'payment.completed',
    payload: ['payment_id' => 789, 'amount' => 149.99],
    endpoints: [
        'https://api.partner1.com/webhook',
        'https://api.partner2.com/webhook',
    ],
    options: [
        'secret' => 'shared-secret',
        'retry' => 3,
    ]
);

foreach ($results as $endpoint => $success) {
    echo "$endpoint: " . ($success ? 'Success' : 'Failed') . "\n";
}
```

### Async Queue-Based Dispatch

```php
// Queue webhook for async processing
$dispatcher->queue(
    event: 'invoice.generated',
    payload: ['invoice_id' => 101],
    endpoint: 'https://billing.example.com/webhook',
    options: [
        'secret' => 'billing-secret',
        'queue' => 'webhooks', // Queue name
    ]
);

echo "Webhook queued successfully!";
```

### Using WebhookManager

```php
use Toporia\Webhook\WebhookManager;

$manager = app(WebhookManager::class);

// Dispatch to all active endpoints matching event pattern
$results = $manager->dispatch(
    event: 'user.created',
    payload: ['user_id' => 123, 'email' => 'user@example.com'],
    async: true // Queue for async processing
);
```

### Managing Webhook Endpoints

```php
use Toporia\Webhook\Models\WebhookEndpoint;

// Create endpoint
$endpoint = WebhookEndpoint::create([
    'name' => 'Payment Service',
    'url' => 'https://payments.example.com/webhook',
    'secret' => 'payment-secret-key',
    'events' => ['payment.*', 'refund.*'], // Wildcard patterns
    'active' => true,
    'timeout' => 30,
    'retry_count' => 3,
    'retry_delay' => 1000,
    'headers' => [
        'X-Service-ID' => 'payment-service',
    ],
    'metadata' => [
        'service_type' => 'payment',
        'priority' => 'high',
    ],
]);

// Update endpoint
$endpoint->update(['active' => false]);

// Check if endpoint should receive event
if ($endpoint->shouldReceive('payment.completed')) {
    echo "Endpoint will receive this event";
}

// Event pattern matching examples:
// 'payment.*' matches: payment.completed, payment.failed, payment.refunded
// 'user.created' matches: user.created (exact match only)
// Empty array matches: all events
```

### Tracking Deliveries

```php
use Toporia\Webhook\Models\WebhookDelivery;

// Get delivery history for endpoint
$deliveries = WebhookDelivery::where('endpoint_id', $endpointId)
    ->orderBy('created_at', 'desc')
    ->get();

foreach ($deliveries as $delivery) {
    echo "Event: {$delivery->event}\n";
    echo "Status: {$delivery->status_code}\n";
    echo "Attempts: {$delivery->attempts}\n";
    echo "Success: " . ($delivery->succeeded_at ? 'Yes' : 'No') . "\n";
}

// Get failed deliveries from last 7 days
$failed = WebhookDelivery::whereNotNull('failed_at')
    ->where('created_at', '>', now()->subDays(7))
    ->get();
```

### Dead Letter Queue (DLQ)

```php
use Toporia\Webhook\Models\WebhookFailure;

// Get pending failures (not yet retried)
$pendingFailures = WebhookFailure::pending()->get();

foreach ($pendingFailures as $failure) {
    echo "Event: {$failure->event}\n";
    echo "Endpoint: {$failure->endpoint_url}\n";
    echo "Attempts: {$failure->total_attempts}\n";
    echo "Error: {$failure->last_error_message}\n";

    // Manually retry failed webhook
    if ($failure->canRetry()) {
        $dispatcher->dispatchTo(
            $failure->event,
            $failure->payload,
            $failure->endpoint_url,
            $failure->options
        );
        $failure->markAsRetried();
    }
}

// Filter by event
$paymentFailures = WebhookFailure::forEvent('payment.failed')->pending()->get();
```

## Inbound Webhooks

### Basic Webhook Receiver

```php
use Toporia\Webhook\Contracts\WebhookReceiverInterface;
use Toporia\Framework\Http\Request;

$receiver = app(WebhookReceiverInterface::class);
$secret = config('webhook.secret');

try {
    // Verify signature and process webhook
    $data = $receiver->process($request, $secret, function ($event, $payload, $request) {
        logger()->info("Webhook received: {$event}", $payload);

        // Process event
        match ($event) {
            'payment.completed' => processPayment($payload),
            'payment.failed' => handlePaymentFailure($payload),
            'refund.processed' => processRefund($payload),
            default => logger()->warning("Unhandled event: {$event}"),
        };
    });

    return response()->json(['success' => true], 200);
} catch (\RuntimeException $e) {
    logger()->error('Webhook processing failed', [
        'error' => $e->getMessage(),
        'endpoint' => $request->path(),
    ]);

    return response()->json([
        'error' => 'Invalid webhook signature or timestamp',
    ], 401);
}
```

### Signature Verification Only

```php
$isValid = $receiver->verifySignature($request, $secret, 'sha256');

if (!$isValid) {
    return response()->json(['error' => 'Invalid signature'], 401);
}

// Extract event and payload manually
$event = $receiver->extractEvent($request);
$payload = $receiver->extractPayload($request);

// Process webhook
handleWebhook($event, $payload);
```

### Custom Webhook Controller

```php
namespace App\Presentation\Http\Controllers;

use Toporia\Framework\Http\{Request, JsonResponse};
use Toporia\Webhook\Contracts\WebhookReceiverInterface;

class WebhookController
{
    public function __construct(
        private WebhookReceiverInterface $receiver
    ) {}

    public function handleStripeWebhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        try {
            $this->receiver->process($request, $secret, function ($event, $payload) {
                event("stripe.{$event}", $payload);
            });

            return new JsonResponse(['received' => true], 200);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function handleGitHubWebhook(Request $request): JsonResponse
    {
        $secret = config('services.github.webhook_secret');

        try {
            $this->receiver->process($request, $secret, function ($event, $payload) {
                // GitHub-specific handling
                match ($event) {
                    'push' => $this->handlePush($payload),
                    'pull_request' => $this->handlePullRequest($payload),
                    default => null,
                };
            });

            return new JsonResponse(['received' => true], 200);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
```

## Security

### HMAC Signature Generation

```php
use Toporia\Webhook\SignatureGenerator;

$generator = new SignatureGenerator('sha256');

$payload = [
    'event' => 'user.created',
    'timestamp' => time(),
    'data' => ['user_id' => 123],
];

$secret = 'your-secret-key';

// Generate signature
$signature = $generator->generate($payload, $secret);

// Send in header
$headers = [
    'X-Webhook-Signature' => $signature,
    'X-Webhook-Signature-Algorithm' => 'sha256',
];
```

### Signature Verification (Inbound)

```php
// Extract signature from request headers
$signature = $request->header('X-Webhook-Signature');

// Verify signature
$isValid = $generator->verify($signature, $payload, $secret);

if (!$isValid) {
    throw new \RuntimeException('Invalid webhook signature');
}
```

### Replay Attack Protection

The package automatically validates webhook timestamps:

- Webhooks older than **5 minutes** are rejected
- Webhooks with future timestamps (beyond 5 minutes) are rejected
- Prevents replay attacks and clock skew issues

```php
// Automatic timestamp validation in WebhookReceiver::process()
// Payload must include 'timestamp' field:
$payload = [
    'event' => 'order.created',
    'timestamp' => time(), // Unix timestamp
    'data' => [...],
];
```

### Security Best Practices

1. **Always Use HTTPS**: Never send webhooks over HTTP
2. **Strong Secrets**: Use cryptographically secure random secrets (32+ characters)
3. **Secret Rotation**: Rotate webhook secrets regularly (every 90 days)
4. **Validate Payloads**: Always validate webhook payload structure
5. **Rate Limiting**: Implement rate limiting for inbound webhooks
6. **IP Whitelisting**: Restrict inbound webhooks to known IPs (if applicable)
7. **Log Everything**: Log all webhook attempts for audit trails

## Reliability Features

### Exponential Backoff with Jitter

Automatic retry delays prevent thundering herd:

- **Attempt 1**: 1 second + random jitter (0-1000ms)
- **Attempt 2**: 2 seconds + jitter
- **Attempt 3**: 4 seconds + jitter
- **Attempt 4**: 8 seconds + jitter
- **Max Delay**: 60 seconds

```php
// Configured automatically in WebhookDispatcher
// Jitter prevents multiple webhooks from retrying simultaneously
```

### Idempotency Keys

Every webhook includes a unique idempotency key:

```php
// Generated automatically as SHA-256 hash of:
// - event name
// - payload
// - endpoint URL
// - timestamp
// - random nonce

// Sent in header:
// X-Webhook-Idempotency-Key: <sha256-hash>
```

### Dead Letter Queue

Failed webhooks are stored in `webhook_failures` table:

```php
// Automatically stored after all retries fail
// Includes:
// - Full payload
// - All options
// - Error message
// - HTTP status code
// - Response body
// - Total attempts

// Query DLQ
$failures = WebhookFailure::pending()
    ->where('created_at', '>', now()->subDays(7))
    ->get();
```

## Database Schema

### `webhook_endpoints` Table

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| name | VARCHAR(255) | Endpoint name |
| url | VARCHAR(500) | Webhook URL |
| secret | VARCHAR(255) | HMAC secret |
| events | JSON | Event patterns (e.g., ["payment.*"]) |
| active | BOOLEAN | Active status |
| timeout | INT | Request timeout (seconds) |
| retry_count | INT | Number of retries |
| retry_delay | INT | Retry delay (milliseconds) |
| headers | JSON | Custom HTTP headers |
| metadata | JSON | Additional metadata |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

### `webhook_deliveries` Table

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| endpoint_id | BIGINT | Foreign key to endpoints |
| event | VARCHAR(255) | Event name |
| payload | JSON | Event payload |
| status_code | INT | HTTP status code |
| response_body | TEXT | Response body |
| attempts | INT | Number of attempts |
| idempotency_key | VARCHAR(64) | Unique delivery key |
| succeeded_at | TIMESTAMP | Success timestamp |
| failed_at | TIMESTAMP | Failure timestamp |
| error_message | TEXT | Error message |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

### `webhook_failures` Table (Dead Letter Queue)

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| endpoint_id | BIGINT | Foreign key to endpoints |
| endpoint_url | VARCHAR(500) | Webhook URL |
| event | VARCHAR(255) | Event name |
| payload | JSON | Event payload |
| options | JSON | Original options |
| total_attempts | INT | Total retry attempts |
| last_error_message | TEXT | Last error message |
| last_status_code | INT | Last HTTP status code |
| last_response_body | TEXT | Last response body |
| idempotency_key | VARCHAR(64) | Unique delivery key |
| retried_at | TIMESTAMP | Manual retry timestamp |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

## Code Examples

### E-commerce Order Webhooks

```php
use Toporia\Webhook\WebhookManager;

class OrderService
{
    public function __construct(private WebhookManager $webhook) {}

    public function createOrder(array $data): Order
    {
        $order = Order::create($data);

        // Dispatch webhook asynchronously
        $this->webhook->dispatch(
            event: 'order.created',
            payload: [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'total' => $order->total,
                'items' => $order->items->toArray(),
                'created_at' => $order->created_at->toIso8601String(),
            ],
            async: true
        );

        return $order;
    }

    public function shipOrder(Order $order): void
    {
        $order->update(['status' => 'shipped']);

        $this->webhook->dispatch(
            event: 'order.shipped',
            payload: [
                'order_id' => $order->id,
                'tracking_number' => $order->tracking_number,
                'carrier' => $order->carrier,
                'shipped_at' => now()->toIso8601String(),
            ],
            async: true
        );
    }
}
```

### Payment Gateway Integration

```php
class PaymentWebhookController
{
    public function __construct(
        private WebhookReceiverInterface $receiver
    ) {}

    public function handleStripe(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        try {
            $this->receiver->process($request, $secret, function ($event, $payload) {
                match ($event) {
                    'payment_intent.succeeded' => $this->markPaymentSucceeded($payload),
                    'payment_intent.payment_failed' => $this->markPaymentFailed($payload),
                    'charge.refunded' => $this->processRefund($payload),
                    'customer.subscription.created' => $this->createSubscription($payload),
                    'customer.subscription.deleted' => $this->cancelSubscription($payload),
                    default => logger()->info("Unhandled Stripe event: {$event}"),
                };
            });

            return new JsonResponse(['received' => true], 200);
        } catch (\RuntimeException $e) {
            logger()->error('Stripe webhook failed', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }
    }

    private function markPaymentSucceeded(array $payload): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $payload['id'])->first();
        $payment?->update(['status' => 'succeeded']);
    }
}
```

## Troubleshooting

### Webhook Not Delivered

**Check:**
1. Endpoint URL is reachable (test with curl)
2. Secret key is correct
3. Network/firewall allows outbound HTTPS
4. Check logs: `storage/logs/app.log`
5. Review DLQ: `WebhookFailure::pending()->get()`

```bash
# Test endpoint manually
curl -X POST https://example.com/webhook \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: test-signature" \
  -d '{"event":"test.event","data":{}}'
```

### Invalid Signature Error

**Check:**
1. Secret key matches on both ends
2. Payload is not modified before verification
3. Algorithm matches (sha256, sha1, sha512)
4. Headers are correctly extracted

```php
// Debug signature verification
$generator = new SignatureGenerator('sha256');
$payload = $request->json();
$expectedSignature = $generator->generate($payload, $secret);
$receivedSignature = $request->header('X-Webhook-Signature');

logger()->debug('Signature verification', [
    'expected' => $expectedSignature,
    'received' => $receivedSignature,
    'match' => hash_equals($expectedSignature, $receivedSignature),
]);
```

### Replay Attack Rejection

**Check:**
1. Server clock is synchronized (use NTP)
2. Timestamp is within 5-minute window
3. Timestamp is in Unix format (seconds, not milliseconds)

```php
// Check timestamp
$timestamp = $payload['timestamp'];
$currentTime = time();
$age = abs($currentTime - $timestamp);

logger()->debug('Timestamp validation', [
    'webhook_time' => $timestamp,
    'current_time' => $currentTime,
    'age_seconds' => $age,
    'max_age' => 300,
]);
```

### Queue Not Processing

**Check:**
1. Queue worker is running: `php console queue:work`
2. Queue configuration: `config/queue.php`
3. Redis is running (if using Redis queue)

```bash
# Start queue worker
php console queue:work webhooks

# Check queue status (Redis)
redis-cli LLEN queues:webhooks
```

## Performance

### Benchmarks

- **Single Webhook Dispatch**: ~50ms (synchronous)
- **Queue Webhook**: ~5ms (async)
- **Signature Generation**: ~0.5ms
- **Signature Verification**: ~0.5ms
- **Database Insert**: ~2ms

### Optimization Tips

1. **Use Async Dispatch**: Queue webhooks for non-blocking operations
2. **Batch Events**: Group multiple events when possible
3. **Database Indexing**: Ensure proper indexes on delivery tables
4. **Connection Pooling**: Reuse HTTP connections
5. **Disable Tracking**: For high-volume, low-priority webhooks

```php
// Disable delivery tracking for performance
$options = ['endpoint_id' => null]; // Skips tracking
$dispatcher->dispatchTo($event, $payload, $endpoint, $options);
```

### Scaling Recommendations

- **High Volume**: Use dedicated queue workers for webhooks
- **Geographic Distribution**: Deploy workers in multiple regions
- **Rate Limiting**: Limit webhooks per endpoint (e.g., 1000/hour)
- **Partitioning**: Partition `webhook_deliveries` table by date

## Testing

### Unit Testing

```php
use Toporia\Webhook\WebhookDispatcher;
use Toporia\Framework\Http\Contracts\HttpClientInterface;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    public function testWebhookDispatch()
    {
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturn(new MockResponse(200, '{"success":true}'));

        $dispatcher = new WebhookDispatcher($mockClient, new SignatureGenerator());

        $success = $dispatcher->dispatchTo(
            'test.event',
            ['data' => 'test'],
            'https://example.com/webhook',
            ['secret' => 'test-secret']
        );

        $this->assertTrue($success);
    }
}
```

### Integration Testing

```php
use Toporia\Webhook\Models\WebhookEndpoint;

class WebhookIntegrationTest extends TestCase
{
    public function testEndpointFiltering()
    {
        $endpoint = WebhookEndpoint::create([
            'name' => 'Test Endpoint',
            'url' => 'https://test.com/webhook',
            'events' => ['payment.*'],
            'active' => true,
        ]);

        $this->assertTrue($endpoint->shouldReceive('payment.completed'));
        $this->assertTrue($endpoint->shouldReceive('payment.failed'));
        $this->assertFalse($endpoint->shouldReceive('order.created'));
    }
}
```

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Setup

```bash
git clone https://github.com/Minhphung7820/toporia.git
cd toporia/packages/webhook
composer install
vendor/bin/phpunit
```

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

- **Documentation**: [https://github.com/Minhphung7820/toporia/blob/main/docs/WEBHOOK.md](https://github.com/Minhphung7820/toporia/blob/main/docs/WEBHOOK.md)
- **Issues**: [https://github.com/Minhphung7820/toporia/issues](https://github.com/Minhphung7820/toporia/issues)
- **Email**: minhphung485@gmail.com

---

**Built with care by the Toporia Framework team.**
