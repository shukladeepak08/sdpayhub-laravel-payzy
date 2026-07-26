# Payzy

[![CI](https://github.com/sdpayhub/laravel-payzy/actions/workflows/ci.yml/badge.svg)](https://github.com/sdpayhub/laravel-payzy/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![Coverage](https://img.shields.io/badge/coverage-95%25%2B-brightgreen.svg?style=flat-square)](https://github.com/sdpayhub/laravel-payzy)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/sdpayhub/laravel-payzy.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-payzy)
[![Total Downloads](https://img.shields.io/packagist/dt/sdpayhub/laravel-payzy.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-payzy)
[![License](https://img.shields.io/packagist/l/sdpayhub/laravel-payzy.svg?style=flat-square)](LICENSE)

Enterprise-grade Laravel payments with one fluent API for **Razorpay**, **Stripe**, **PayPal**, **Paytm**, and **PhonePe**.

```php
use Sdpayhub\Payzy\Facades\Payzy;

$response = Payzy::gateway('razorpay')
    ->amount(1000)
    ->currency('INR')
    ->orderId('ORDER-1001')
    ->create();
```

Every gateway method returns a typed `PaymentResponse`. Webhooks, idempotency, signature verification, and queued processing are built in.

## Why this package

Most Laravel payment packages wrap a single provider or stop at “create order / redirect”. Payzy is built for apps that need **multiple Indian and global gateways** behind one contract, without reinventing security plumbing.

Compared to [mdiqbal/laravel-payments](https://github.com/mdiqbal/laravel-payments) and similar wrappers:

| Concern | Typical wrappers | Payzy |
|---|---|---|
| API shape | Gateway-specific arrays / mixed returns | Unified `PaymentResponse` from every method |
| Webhooks | DIY routes + signature checks | Auto-registered `POST /payzy/webhooks/{gateway}`, verification, replay protection, queue |
| Idempotency | Rarely first-class | Cache or database store, fingerprint conflict detection |
| Observability | Ad-hoc logging | Masked secrets, structured events |
| Extensibility | Fork or monkey-patch | `GatewayInterface` + `GatewayFactory::extend()` |
| Typing / static analysis | Often loose | PHPStan level max, Pest tests |

Honest trade-off: this package is a **gateway orchestration layer**, not a full billing product like Laravel Cashier (no invoice UI, tax, or plan catalog). If you need Stripe-only subscriptions with Cashier’s model layer, use Cashier. If you need Razorpay + Stripe + PayPal + Paytm + PhonePe with production webhook hygiene, use this.

## Requirements

- PHP **8.1+**
- Laravel **10**, **11**, or **12**
- A queue worker when webhooks are enabled (recommended)
- Gateway credentials for the providers you use

## Installation

```bash
composer require sdpayhub/laravel-payzy
```

The service provider and `Payment` facade are auto-discovered.

Publish the config (and optionally routes / migrations):

```bash
php artisan vendor:publish --tag=payzy-config
php artisan vendor:publish --tag=payzy-routes
php artisan vendor:publish --tag=payzy-migrations
```

If you use the database idempotency driver:

```bash
php artisan migrate
```

## Configuration

Config is published as `config/payzy.php` (key: `payzy`).

### Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `PAYZY_DEFAULT_GATEWAY` | Default gateway name | `razorpay` |
| `PAYZY_MODE` | `sandbox` or `live` | `sandbox` |
| `PAYZY_CURRENCY` | Fallback currency | `INR` |
| `RAZORPAY_KEY` / `RAZORPAY_SECRET` / `RAZORPAY_WEBHOOK_SECRET` | Razorpay | — |
| `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` | Stripe | — |
| `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` / `PAYPAL_WEBHOOK_ID` | PayPal | — |
| `PAYTM_MERCHANT_ID` / `PAYTM_MERCHANT_KEY` | Paytm | — |
| `PHONEPE_CLIENT_ID` / `PHONEPE_CLIENT_SECRET` / `PHONEPE_MERCHANT_ID` / `PHONEPE_SALT_KEY` | PhonePe | — |
| `PAYZY_WEBHOOKS_ENABLED` | Register webhook routes | `true` |
| `PAYZY_WEBHOOK_PREFIX` | Route prefix | `payzy/webhooks` |
| `PAYZY_IDEMPOTENCY_ENABLED` | Idempotency layer | `true` |
| `PAYZY_IDEMPOTENCY_DRIVER` | `cache` or `database` | `cache` |
| `PAYZY_LOGGING` | HTTP logging with secret masking | `true` |

See `config/payzy.php` for timeouts, retries, webhook queue settings, and per-gateway base URLs.

## Quick start

### Fluent create

```php
use Sdpayhub\Payzy\Facades\Payzy;

$response = Payzy::gateway('razorpay')
    ->amount(1000)           // minor units (paise / cents)
    ->currency('INR')
    ->orderId('ORDER-1001')
    ->idempotencyKey('checkout-ORDER-1001')
    ->meta(['user_id' => 42])
    ->create();

if ($response->isSuccess()) {
    $orderId = $response->getGatewayTransactionId();
}

if ($response->isRedirect()) {
    return redirect()->away($response->getRedirectUrl());
}
```

Omitting the gateway name uses `payment.default`:

```php
Payzy::gateway()->amount(500)->currency('INR')->orderId('X')->create();
```

### Array charge API

```php
$response = Payzy::using('stripe')->charge([
    'amount' => 2000,
    'currency' => 'usd',
    'order_id' => 'ORDER-2001',
]);

// Alias of charge():
Payzy::using('stripe')->create([...]);
```

## Core operations

All methods return `Sdpayhub\Payzy\DTOs\PaymentResponse`.

```php
$manager = Payzy::using('razorpay');

$manager->charge(['amount' => 1000, 'currency' => 'INR', 'order_id' => 'X']);
$manager->capture(['payment_id' => 'pay_…', 'amount' => 1000]);
$manager->refund('pay_…');
$manager->partialRefund(['payment_id' => 'pay_…', 'amount' => 250]);
$manager->status('pay_…');
$manager->cancel(['payment_id' => 'pay_…']); // or order_id where supported
$manager->verify(['payment_id' => 'pay_…']);
$manager->verifySignature([...]);
$manager->verifyWebhook(['payload' => $payload, 'raw_body' => $raw, 'headers' => $headers, 'signature' => $sig]);
$manager->paymentLink(['amount' => 1000, 'currency' => 'INR']);
$manager->qr(['amount' => 1000, 'currency' => 'INR']);
```

Convenience forms:

```php
Payzy::refund($paymentId);
Payzy::status($paymentId);
Payzy::capture(['payment_id' => $paymentId, 'amount' => 1000]);
Payzy::cancel(['order_id' => $orderId]);
```

### Customers & subscriptions

Capability methods throw `ConfigurationException` when the active gateway does not support them.

| Capability | Razorpay | Stripe | PayPal | Paytm | PhonePe |
|---|---|---|---|---|---|
| Customers | Yes | Yes | — | — | — |
| Subscriptions | Yes | Yes | Yes | — | — |

```php
Payzy::using('stripe')->createCustomer([
    'email' => 'customer@example.com',
    'name' => 'Ada Lovelace',
]);

Payzy::using('razorpay')->createSubscription([
    'plan_id' => 'plan_…',
    'customer_id' => 'cust_…',
]);

Payzy::using('paypal')->cancelSubscription(['subscription_id' => 'I-…']);
```

Also available: `getCustomer`, `updateCustomer`, `deleteCustomer`, `getSubscription`, `pauseSubscription`, `resumeSubscription`.

## Webhooks

When `payment.webhooks.enabled` is `true`, routes are registered automatically:

```text
POST /payzy/webhooks/{gateway}
```

Examples: `/payzy/webhooks/razorpay`, `/payzy/webhooks/stripe`.

Inbound handling:

1. Resolves the gateway and verifies the provider signature
2. Asserts freshness via timestamp tolerance + nonce / event-id replay guard
3. Dispatches `WebhookReceived`
4. Queues `ProcessWebhookJob` (returns **202 Accepted**)
5. On failure, dispatches `WebhookFailed` with an appropriate HTTP status

Configure queue and tolerance in `config/payzy.php`:

```php
'webhooks' => [
    'enabled' => true,
    'prefix' => 'payzy/webhooks',
    'middleware' => ['api'],
    'timestamp_tolerance_seconds' => 300,
    'nonce_ttl_seconds' => 86400,
    'queue' => 'default',
    'queue_connection' => null,
],
```

Point each provider’s dashboard webhook URL at your public endpoint. Keep webhook secrets only in environment variables.

## Idempotency

Enabled by default. Keys may be supplied on the fluent builder or payload; when `auto_generate` is `true`, a UUID is generated.

```php
Payzy::gateway('stripe')
    ->amount(1500)
    ->currency('USD')
    ->orderId('ORDER-9')
    ->idempotencyKey('order-9-charge')
    ->create();

Payzy::using('stripe')->charge([
    'amount' => 1500,
    'currency' => 'USD',
    'idempotency_key' => 'order-9-charge',
]);
```

Reusing a key with a **different payload fingerprint** throws `IdempotencyConflictException`. Drivers: `cache` (default) or `database` (publish & migrate).

## Events

| Event | When |
|---|---|
| `PaymentCreated` | Payment / order / link / QR created |
| `PaymentSuccess` | Successful capture or verify (and successful webhook processing) |
| `PaymentFailed` | Failed create / capture / verify response |
| `RefundCreated` | Refund initiated |
| `RefundCompleted` | Refund reported success |
| `WebhookReceived` | Webhook accepted after verification |
| `WebhookFailed` | Webhook verification or processing failure |

Listener example:

```php
namespace App\Listeners;

use Sdpayhub\Payzy\Events\PaymentSuccess;

final class MarkOrderPaid
{
    public function handle(PaymentSuccess $event): void
    {
        $txn = $event->response->getGatewayTransactionId();

        // Mark order paid using $event->gateway and $txn…
    }
}
```

Register in `EventServiceProvider` or via Laravel 11+ discovery.

## Exceptions

All package exceptions extend `Sdpayhub\Payzy\Exceptions\PayzyException` and expose `getContext()`.

```text
PayzyException
├── ConfigurationException
├── InvalidGatewayException
├── PaymentFailedException
├── RefundFailedException
├── WebhookVerificationException
├── IdempotencyConflictException
└── GatewayTimeoutException
```

```php
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Facades\Payzy;

try {
    Payzy::using('paypal')->charge($payload);
} catch (PayzyException $e) {
    report($e);
    logger()->warning('payment.failed', $e->getContext());
}
```

## Supported gateways

| Gateway | Create / charge | Capture | Refund | Status / cancel | Webhooks | Payment link | QR | Customers | Subscriptions |
|---|---|---|---|---|---|---|---|---|---|
| `razorpay` | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| `stripe` | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| `paypal` | Yes | Yes | Yes | Yes | Yes | Yes | Yes | — | Yes |
| `paytm` | Yes | Yes | Yes | Yes | Yes | Yes | Yes | — | — |
| `phonepe` | Yes | Yes | Yes | Yes | Yes | Yes | Yes | — | — |

Amounts are integers in the gateway’s minor currency unit unless a provider requires otherwise in its own API docs.

## Extending

Implement `GatewayInterface` (and optionally `SupportsCustomers` / `SupportsSubscriptions`). Extending `AbstractGateway` is recommended for config validation and HTTP helpers.

```php
use Sdpayhub\Payzy\Contracts\GatewayInterface;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Factories\GatewayFactory;
use Sdpayhub\Payzy\Gateways\AbstractGateway;

final class AcmeGateway extends AbstractGateway implements GatewayInterface
{
    public function getName(): string
    {
        return 'acme';
    }

    protected function requiredConfigKeys(): array
    {
        return ['api_key'];
    }

    public function create(array $payload): PaymentResponse
    {
        // …
        return PaymentResponse::success(data: [], gatewayTransactionId: 'acme_…');
    }

    // Implement capture, refund, partialRefund, status, cancel,
    // verify, verifySignature, verifyWebhook, paymentLink, qr…
}
```

Register at boot:

```php
use Sdpayhub\Payzy\Factories\GatewayFactory;

public function boot(GatewayFactory $factory): void
{
    $factory->extend('acme', AcmeGateway::class);
}
```

Add matching credentials under `config/payzy.php` → `gateways.acme`.

## Testing

```bash
composer test
composer analyse   # PHPStan level max
composer lint
composer check     # lint + analyse + test
```

Feature tests fake HTTP with Laravel’s HTTP client. Prefer asserting on `PaymentResponse` and dispatched events rather than raw provider arrays.

```php
use Illuminate\Support\Facades\Http;
use Sdpayhub\Payzy\Facades\Payzy;

Http::fake([
    'api.razorpay.com/v1/orders' => Http::response([
        'id' => 'order_ABC',
        'amount' => 1000,
        'currency' => 'INR',
        'status' => 'created',
    ], 200),
]);

$response = Payzy::gateway('razorpay')
    ->amount(1000)
    ->currency('INR')
    ->orderId('ORDER001')
    ->create();

expect($response->isSuccess())->toBeTrue()
    ->and($response->getGatewayTransactionId())->toBe('order_ABC');
```

## Security

- Store all gateway secrets in the environment; never commit them
- Prefer HTTPS-only webhook endpoints; leave signature verification enabled
- Replay protection uses timestamp tolerance and event-id / nonce TTLs
- Logs mask configured secret keys (`key`, `secret`, `token`, card fields, etc.)
- Idempotency keys reduce duplicate charges on retries
- Run PHPStan and the Pest suite in CI before releases

See [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) and [UPGRADING.md](UPGRADING.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Payzy is open-source software licensed under the [MIT license](LICENSE).
