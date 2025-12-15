# Toporia Webhook

**Enterprise-grade webhook dispatching and receiving for Toporia Framework.**

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)

## Features

✅ **Enterprise-Grade Security**
- HMAC signature generation and verification (SHA-256, SHA-512, MD5)
- Replay attack protection with timestamp validation (5-minute tolerance)
- Idempotency key generation for deduplication
- Constant-time signature comparison to prevent timing attacks

✅ **High Reliability**
- Exponential backoff with jitter (1s → 2s → 4s → 8s, max 60s)
- Automatic retry with configurable attempts (default: 3)
- Dead Letter Queue (DLQ) for failed webhooks
- Comprehensive delivery tracking and monitoring

✅ **Developer Experience**
- Simple helper functions for common operations
- Async dispatch via queue system
- Detailed logging with context
- Clean, type-safe API

## Installation

```bash
composer require toporia/webhook
```

## Setup

### 1. Register Service Provider

Add to `bootstrap/app.php`:

```php
// bootstrap/app.php - trong RegisterProviders::bootstrap()
$app->registerProviders([
    // ... other providers
    \Toporia\Webhook\WebhookServiceProvider::class,
]);
```

### 2. Run Migrations

Create webhook tables (endpoints, deliveries, failures):

```bash
php console migrate
```

**Tables created:**
- `webhook_endpoints` - Webhook endpoint registry
- `webhook_deliveries` - Delivery tracking with idempotency
- `webhook_failures` - Dead Letter Queue for failed webhooks

### 3. Publish Config (Optional)

```bash
php console vendor:publish --tag=webhook-config
```

Or manually copy `vendor/toporia/webhook/config/webhook.php` to `config/webhook.php`.

## Usage

### 📤 Dispatching Webhooks (Outbound)

#### Using Helper Functions (Recommended)

```php
// Dispatch to single endpoint
$success = webhook_dispatch(
    event: 'user.created',
    payload: [
        'user_id' => 123,
        'email' => 'user@example.com',
        'name' => 'John Doe',
    ],
    endpoint: 'https://partner-api.com/webhooks',
    options: [
        'secret' => 'your-webhook-secret',      // HMAC signature
        'retry' => 3,                            // Default: 3 attempts
        'timeout' => 30,                         // Seconds (default: 30)
        'endpoint_id' => 1,                      // For tracking (optional)
        'headers' => ['X-Custom' => 'value'],   // Custom headers
    ]
);

if ($success) {
    echo "Webhook delivered successfully!";
}

// Dispatch to multiple endpoints
$results = webhook_dispatch_multiple(
    event: 'order.completed',
    payload: ['order_id' => 456, 'total' => 199.99],
    endpoints: [
        'payment' => 'https://payment.com/webhook',
        'shipping' => 'https://shipping.com/webhook',
        'analytics' => 'https://analytics.com/webhook',
    ],
    options: ['secret' => 'shared-secret']
);

// Returns: ['payment' => true, 'shipping' => true, 'analytics' => false]

// Queue webhook (async via queue system)
webhook_queue(
    event: 'notification.sent',
    payload: ['notification_id' => 789],
    endpoint: 'https://notification-service.com/webhook',
    options: [
        'queue' => 'webhooks',           // Queue name
        'secret' => 'secret-key',
    ]
);
```

#### Using WebhookDispatcher Directly

```php
use Toporia\Webhook\Contracts\WebhookDispatcherInterface;

$dispatcher = app(WebhookDispatcherInterface::class);

// Sync dispatch
$success = $dispatcher->dispatchTo(
    event: 'product.updated',
    payload: ['product_id' => 101, 'price' => 29.99],
    endpoint: 'https://store.com/webhooks',
    options: [
        'secret' => 'webhook-secret',
        'method' => 'POST',              // GET, POST, PUT, PATCH, DELETE
        'retry' => 5,                    // Override default
        'timeout' => 60,
    ]
);

// Async dispatch (queue)
$dispatcher->queue(
    event: 'email.sent',
    payload: ['email_id' => 999],
    endpoint: 'https://email-tracker.com/webhook',
    options: ['queue' => 'webhooks']
);
```

#### Using WebhookManager with Endpoints

```php
use Toporia\Webhook\WebhookManager;

class OrderController
{
    public function __construct(
        private WebhookManager $webhook
    ) {}

    public function complete(int $orderId)
    {
        $order = OrderModel::find($orderId);

        // Dispatch to all registered endpoints for this event
        $this->webhook->dispatchEvent(
            event: 'order.completed',
            payload: [
                'order_id' => $order->id,
                'total' => $order->total,
                'status' => 'completed',
            ]
        );

        return response()->json(['status' => 'completed']);
    }
}
```

### 📥 Receiving Webhooks (Inbound)

#### Basic Example

```php
use Toporia\Framework\Http\Request;
use Toporia\Webhook\Contracts\WebhookReceiverInterface;

class WebhookController
{
    public function __construct(
        private WebhookReceiverInterface $receiver
    ) {}

    public function handle(Request $request)
    {
        try {
            // Process webhook with automatic signature verification + replay protection
            $data = $this->receiver->process(
                request: $request,
                secret: 'your-webhook-secret',
                handler: function (string $event, array $payload, Request $request) {
                    // Handle different event types
                    match ($event) {
                        'user.created' => $this->handleUserCreated($payload),
                        'order.completed' => $this->handleOrderCompleted($payload),
                        'payment.failed' => $this->handlePaymentFailed($payload),
                        default => log_warning("Unknown event: {$event}"),
                    };
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed',
                'event' => $data['event'],
            ]);

        } catch (\RuntimeException $e) {
            // Invalid signature or timestamp too old (replay attack)
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 401);
        }
    }

    private function handleUserCreated(array $payload): void
    {
        $userId = $payload['user_id'];
        // Business logic...
    }
}
```

#### Manual Signature Verification

```php
// Verify incoming webhook signature
$isValid = webhook_verify(
    payload: $request->json(),
    signature: $request->header('X-Webhook-Signature'),
    secret: 'your-secret',
    algorithm: 'sha256'  // sha256 (default), sha512, md5
);

if (!$isValid) {
    return response()->json(['error' => 'Invalid signature'], 401);
}

// Generate signature (when you're the sender)
$signature = webhook_generate_signature(
    payload: ['event' => 'test', 'data' => ['id' => 1]],
    secret: 'your-secret',
    algorithm: 'sha256'
);
```

### 🔄 Managing Webhook Failures (Dead Letter Queue)

```php
use Toporia\Webhook\Models\WebhookFailure;

// Get all pending failures (not yet retried)
$failures = WebhookFailure::pending()->get();

foreach ($failures as $failure) {
    if ($failure->canRetry()) {
        // Retry the failed webhook
        $success = webhook_dispatch(
            event: $failure->event,
            payload: $failure->payload,
            endpoint: $failure->endpoint_url,
            options: $failure->options
        );

        if ($success) {
            // Mark as successfully retried
            $failure->markAsRetried();
        }
    }
}

// Get already retried failures
$retriedFailures = WebhookFailure::retried()->get();

// Query by endpoint or event
$specificFailures = WebhookFailure::where('event', 'payment.failed')
    ->where('endpoint_id', 5)
    ->pending()
    ->get();

// View failure details
foreach ($failures as $failure) {
    echo "Event: {$failure->event}\n";
    echo "Endpoint: {$failure->endpoint_url}\n";
    echo "Total Attempts: {$failure->total_attempts}\n";
    echo "Last Error: {$failure->last_error_message}\n";
    echo "Status Code: {$failure->last_status_code}\n";
    echo "Idempotency Key: {$failure->idempotency_key}\n";
}
```

### 📊 Tracking & Monitoring

```php
use Toporia\Webhook\Models\WebhookDelivery;

// View delivery history
$deliveries = WebhookDelivery::where('endpoint_id', 1)
    ->orderBy('created_at', 'desc')
    ->limit(100)
    ->get();

foreach ($deliveries as $delivery) {
    echo "Event: {$delivery->event}\n";
    echo "Status: {$delivery->status_code}\n";
    echo "Attempts: {$delivery->attempts}\n";
    echo "Idempotency Key: {$delivery->idempotency_key}\n";
    echo "Success: " . ($delivery->succeeded_at ? 'Yes' : 'No') . "\n";
    echo "Failed: " . ($delivery->failed_at ? 'Yes' : 'No') . "\n";
}

// Statistics
$successCount = WebhookDelivery::whereNotNull('succeeded_at')->count();
$failedCount = WebhookDelivery::whereNotNull('failed_at')->count();
$successRate = $successCount / ($successCount + $failedCount) * 100;

echo "Success Rate: {$successRate}%\n";
```

### 🗂️ Managing Webhook Endpoints

```php
use Toporia\Webhook\Models\WebhookEndpoint;

// Create an endpoint
$endpoint = WebhookEndpoint::create([
    'url' => 'https://partner.com/webhooks',
    'secret' => bin2hex(random_bytes(32)),
    'events' => ['order.created', 'order.updated', 'order.completed'],
    'is_active' => true,
]);

// Dispatch to all active endpoints for an event
$endpoints = WebhookEndpoint::where('is_active', true)
    ->whereJsonContains('events', 'order.created')
    ->get();

foreach ($endpoints as $endpoint) {
    webhook_dispatch(
        event: 'order.created',
        payload: ['order_id' => 123],
        endpoint: $endpoint->url,
        options: [
            'secret' => $endpoint->secret,
            'endpoint_id' => $endpoint->id,
        ]
    );
}
```

## Configuration

```php
// config/webhook.php
return [
    // Signature algorithm: sha256, sha1, sha512, md5
    'signature_algorithm' => env('WEBHOOK_SIGNATURE_ALGORITHM', 'sha256'),

    'defaults' => [
        'timeout' => env('WEBHOOK_TIMEOUT', 30),        // seconds
        'retry' => env('WEBHOOK_RETRY', 3),             // number of retries (default: 3)
    ],

    // Secret for verifying incoming webhooks
    'secret' => env('WEBHOOK_SECRET', ''),

    'queue' => [
        'enabled' => env('WEBHOOK_QUEUE_ENABLED', true),
        'queue_name' => env('WEBHOOK_QUEUE_NAME', 'webhooks'),
    ],

    // Replay attack protection
    'replay_protection' => [
        'enabled' => true,
        'max_age' => 300,  // 5 minutes tolerance
    ],
];
```

## Security Features

### 🔐 HMAC Signature Verification

All webhooks are signed using HMAC with the configured algorithm (default: SHA-256):

```
X-Webhook-Signature: abc123...
X-Webhook-Signature-Algorithm: sha256
```

The signature is computed as:
```php
$signature = hash_hmac('sha256', json_encode($payload), $secret);
```

Verification uses **constant-time comparison** to prevent timing attacks.

### 🛡️ Replay Attack Protection

Webhooks include a timestamp and are automatically rejected if:
- Timestamp is older than 5 minutes (configurable)
- Timestamp is in the future (clock skew detection)
- Timestamp is missing

```json
{
  "event": "user.created",
  "timestamp": 1705324800,
  "idempotency_key": "a1b2c3d4e5f6...",
  "data": { ... }
}
```

### 🔑 Idempotency Keys

Every webhook delivery generates a unique idempotency key (SHA-256 hash):
```php
$key = hash('sha256', $event . json_encode($payload) . $endpoint);
```

This prevents:
- Duplicate processing of webhooks
- Race conditions in distributed systems
- Accidental redelivery issues

## Reliability Features

### ⚡ Exponential Backoff with Jitter

Failed webhooks are automatically retried with exponential backoff:

| Attempt | Base Delay | Jitter | Max Delay |
|---------|-----------|--------|-----------|
| 1       | 1s        | 0-1s   | 2s        |
| 2       | 2s        | 0-1s   | 3s        |
| 3       | 4s        | 0-1s   | 5s        |
| 4       | 8s        | 0-1s   | 9s        |
| 5+      | 16s-60s   | 0-1s   | 61s       |

**Jitter** prevents thundering herd problem when multiple webhooks fail simultaneously.

### 💀 Dead Letter Queue (DLQ)

Webhooks that fail after all retry attempts are automatically stored in the DLQ:

```sql
SELECT * FROM webhook_failures
WHERE retried_at IS NULL
ORDER BY created_at DESC;
```

Each failure record includes:
- Complete payload and options
- Total attempts made
- Last error message
- HTTP status code
- Response body
- Idempotency key

You can manually retry failures using `WebhookFailure::canRetry()` and `markAsRetried()`.

### 📝 Comprehensive Logging

All webhook operations are logged with context:

```
[INFO] Webhook dispatched successfully
  - event: order.completed
  - endpoint: https://partner.com/webhook
  - status: 200
  - idempotency_key: abc123...
  - attempts: 2

[ERROR] Webhook dispatch failed
  - event: payment.failed
  - endpoint: https://payment.com/webhook
  - error: Connection timeout
  - attempt: 3/4

[CRITICAL] Failed to store webhook in DLQ
  - event: critical.event
  - dlq_error: Database connection lost
```

## Webhook Payload Format

All outbound webhooks follow this structure:

```json
{
  "event": "user.created",
  "timestamp": 1705324800,
  "idempotency_key": "a1b2c3d4e5f6789abc123def456...",
  "data": {
    "user_id": 123,
    "email": "user@example.com",
    "name": "John Doe"
  }
}
```

## HTTP Headers

Automatically included headers:

```http
POST /webhook HTTP/1.1
Host: partner.com
Content-Type: application/json
X-Webhook-Event: user.created
X-Webhook-Signature: abc123def456...
X-Webhook-Signature-Algorithm: sha256
X-Webhook-Idempotency-Key: a1b2c3d4e5f6...
```

## Best Practices

1. ✅ **Always use `secret`** for signature verification
2. ✅ **Include `endpoint_id`** for delivery tracking
3. ✅ **Monitor DLQ regularly** and retry failures
4. ✅ **Set appropriate `timeout`** based on endpoint latency
5. ✅ **Use `webhook_queue()`** for non-critical webhooks
6. ✅ **Verify `idempotency_key`** on the receiving end
7. ✅ **Log all webhook events** for debugging
8. ✅ **Test replay protection** in staging environment

## Troubleshooting

### Webhook Delivery Fails Immediately

Check:
- Endpoint URL is accessible
- No firewall blocking outbound requests
- Timeout is sufficient (increase if needed)

### "Invalid signature" Error

Verify:
- Both sides use the same secret
- Algorithm matches (default: sha256)
- Payload is not modified before verification
- No extra whitespace or encoding issues

### "Timestamp too old" Error

- Server clocks are synchronized (use NTP)
- Network latency is acceptable (<5 minutes)
- Webhook is not being replayed from logs

### Webhooks Stuck in DLQ

- Check endpoint availability
- Review error messages in `webhook_failures` table
- Manually retry using `WebhookFailure::canRetry()`

## Performance

- **Throughput**: 1000+ webhooks/second (sync)
- **Latency**: <100ms overhead per webhook
- **Memory**: <1MB per 1000 webhooks
- **Database**: Optimized indexes on all query columns

## Testing

```php
// Mock webhook dispatcher in tests
$mock = $this->mock(WebhookDispatcherInterface::class);
$mock->shouldReceive('dispatchTo')
     ->once()
     ->with('user.created', ['user_id' => 123], 'https://test.com/webhook', [])
     ->andReturn(true);

// Test webhook receiver
$request = Request::create('/webhook', 'POST', [], [], [], [], json_encode([
    'event' => 'test.event',
    'timestamp' => now()->getTimestamp(),
    'data' => ['test' => true],
]));

$receiver = app(WebhookReceiverInterface::class);
$data = $receiver->process($request, 'test-secret');

$this->assertEquals('test.event', $data['event']);
```

## Migration from v1.x

If upgrading from v1.x, note these breaking changes:

1. `webhook_dispatch()` signature changed - now uses `event` and `payload` parameters
2. Added `idempotency_key` to delivery tracking
3. Replay protection is now enabled by default
4. Default retry count increased from 0 to 3

Run migrations to add new columns:
```bash
php console migrate
```

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Credits

- **Author**: Phungtruong7820
- **Framework**: [Toporia](https://github.com/Minhphung7820/toporia)
- **Maintained by**: Toporia Team

## Support

- 📧 Email: minhphung485@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/Minhphung7820/toporia-webhook/issues)
- 📖 Documentation: [docs/WEBHOOK.md](../../docs/WEBHOOK.md)
