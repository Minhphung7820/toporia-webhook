# Toporia Webhook

Webhook dispatching and receiving for Toporia Framework.

## Installation

```bash
composer require toporia/webhook
```

## Setup

### 1. Register Service Provider

Add to `bootstrap/app.php` or `App/Infrastructure/Providers/AppServiceProvider.php`:

```php
// bootstrap/app.php - trong RegisterProviders::bootstrap()
$app->registerProviders([
    // ... other providers
    \Toporia\Webhook\WebhookServiceProvider::class,
]);

// Hoặc trong AppServiceProvider
public function register(ContainerInterface $container): void
{
    $container->register(\Toporia\Webhook\WebhookServiceProvider::class);
}
```

### 2. Run Migration (optional)

If you want to track webhook deliveries:

```bash
php console migrate
```

### 3. Publish Config (optional)

```bash
php console vendor:publish --tag=webhook-config
```

Or manually copy `vendor/toporia/webhook/config/webhook.php` to `config/webhook.php`.

## Usage

### 1. Dispatching Webhooks (Outbound)

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

        // Dispatch webhook to external service
        $this->webhook->dispatch(
            url: 'https://partner-api.com/webhooks/orders',
            payload: [
                'event' => 'order.completed',
                'data' => [
                    'order_id' => $order->id,
                    'total' => $order->total,
                    'status' => 'completed',
                ],
            ],
            secret: 'partner-webhook-secret'
        );

        return response()->json(['status' => 'completed']);
    }
}
```

### 2. Using Helper Functions

```php
// Get webhook manager
$manager = webhook();

// Dispatch a webhook
$response = webhook_dispatch(
    'https://api.example.com/webhooks',
    ['event' => 'user.created', 'user_id' => 123],
    'your-secret-key'
);

// Verify incoming webhook
$isValid = webhook_verify($payload, $signature, $secret);
```

### 3. Receiving Webhooks (Inbound)

```php
use Toporia\Webhook\Contracts\WebhookReceiverInterface;

class WebhookController
{
    public function __construct(
        private WebhookReceiverInterface $receiver
    ) {}

    public function handleStripe(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        if (!$this->receiver->verify($payload, $signature, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);

        match ($event['type']) {
            'payment_intent.succeeded' => $this->handlePaymentSuccess($event),
            'payment_intent.failed' => $this->handlePaymentFailed($event),
            default => null,
        };

        return response()->json(['received' => true]);
    }
}
```

### 4. Async Webhook Dispatch (Queue)

```php
use Toporia\Webhook\Jobs\DispatchWebhookJob;

// Dispatch webhook via queue
dispatch(new DispatchWebhookJob(
    url: 'https://api.example.com/webhooks',
    payload: ['event' => 'order.shipped', 'order_id' => 123],
    secret: 'webhook-secret',
    options: ['retry' => 5]
));
```

### 5. Managing Webhook Endpoints

Use the included models to manage webhook endpoints and deliveries:

```php
use Toporia\Webhook\Models\WebhookEndpoint;
use Toporia\Webhook\Models\WebhookDelivery;

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
    $result = webhook_dispatch($endpoint->url, $payload, $endpoint->secret);

    // Track delivery
    WebhookDelivery::create([
        'endpoint_id' => $endpoint->id,
        'event' => 'order.created',
        'payload' => $payload,
        'response_code' => $result['status_code'] ?? null,
        'response_body' => $result['body'] ?? null,
        'delivered_at' => now(),
    ]);
}
```

## Configuration

```php
// config/webhook.php
return [
    // Signature algorithm: sha256, sha1, sha512
    'signature_algorithm' => env('WEBHOOK_SIGNATURE_ALGORITHM', 'sha256'),

    'defaults' => [
        'timeout' => env('WEBHOOK_TIMEOUT', 30), // seconds
        'retry' => env('WEBHOOK_RETRY', 3), // number of retries
        'retry_delay' => env('WEBHOOK_RETRY_DELAY', 1000), // milliseconds
    ],

    // Secret for verifying incoming webhooks
    'secret' => env('WEBHOOK_SECRET', ''),

    'queue' => [
        'enabled' => env('WEBHOOK_QUEUE_ENABLED', true),
        'queue_name' => env('WEBHOOK_QUEUE_NAME', 'webhooks'),
    ],
];
```

## Signature Format

Webhooks are signed using HMAC with the configured algorithm:

```
X-Webhook-Signature: sha256=abc123...
```

The signature is computed as:
```php
$signature = hash_hmac('sha256', $payload, $secret);
```

## Retry Logic

Failed webhook deliveries are automatically retried based on configuration:

- Default: 3 retries
- Exponential backoff between retries
- Failed deliveries are logged

## License

MIT
