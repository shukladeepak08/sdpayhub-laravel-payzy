# Payzy

[![CI](https://github.com/shukladeepak08/sdpayhub-laravel-payzy/actions/workflows/ci.yml/badge.svg)](https://github.com/shukladeepak08/sdpayhub-laravel-payzy/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat-square)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

One simple API for Laravel payments. Use **Razorpay**, **Stripe**, **PayPal**, **Paytm**, or **PhonePe** without learning five different SDKs.

```php
use Sdpayhub\Payzy\Facades\Payzy;

$response = Payzy::gateway('razorpay')
    ->amount(1000)          // amount in paise (₹10.00)
    ->currency('INR')
    ->orderId('ORDER-1001')
    ->create();
```

## Why Payzy?

| What you get | Why it matters |
|---|---|
| Same response shape on every gateway | Switch from Razorpay to Stripe by changing one string |
| Built-in webhook verification | Protects you from fake payment callbacks |
| Replay protection | Same webhook cannot be reused to fake a payment |
| Idempotency keys | Retries do not create duplicate charges |
| Secrets never logged | API keys are masked automatically |
| Typed exceptions | Catch payment errors clearly, not generic `\Exception` |

## Requirements

- PHP 8.1 or newer
- Laravel 10, 11, or 12

## Install (3 steps)

**1. Install the package**

```bash
composer require sdpayhub/laravel-payzy
```

**2. Publish the config**

```bash
php artisan vendor:publish --tag=payzy-config
```

**3. Add your keys to `.env`**

```env
PAYZY_DEFAULT_GATEWAY=razorpay
PAYZY_MODE=sandbox
PAYZY_CURRENCY=INR

RAZORPAY_KEY=your_key
RAZORPAY_SECRET=your_secret
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
```

Use sandbox keys while testing. Never commit `.env`.

## First payment (copy & paste)

```php
use Sdpayhub\Payzy\Facades\Payzy;

$response = Payzy::gateway('razorpay')
    ->amount(1000)
    ->currency('INR')
    ->orderId('ORDER-1001')
    ->create();

if ($response->isSuccess()) {
    // Save this ID in your database
    $transactionId = $response->getGatewayTransactionId();
}

if ($response->isRedirect()) {
    // Send the customer to the payment page
    return redirect()->away($response->getRedirectUrl());
}

// Something went wrong
return back()->with('error', $response->getMessage());
```

### Important: amount format

Pass amounts in **smallest currency units**:

| Currency | Example | Meaning |
|---|---|---|
| INR | `1000` | ₹10.00 |
| USD | `1000` | $10.00 |

## Switch gateway

Only change the gateway name. The rest of your code stays the same.

```php
Payzy::gateway('stripe')->amount(1000)->currency('USD')->orderId('ORDER-1')->create();
Payzy::gateway('paypal')->amount(1000)->currency('USD')->orderId('ORDER-1')->create();
Payzy::gateway('paytm')->amount(1000)->currency('INR')->orderId('ORDER-1')->create();
Payzy::gateway('phonepe')->amount(1000)->currency('INR')->orderId('ORDER-1')->create();
```

## Common operations

```php
$pay = Payzy::using('razorpay');

// Check status
$status = $pay->status('pay_xxxxx');

// Full refund
$refund = $pay->refund('pay_xxxxx');

// Partial refund (amount in paise/cents)
$partial = $pay->partialRefund([
    'payment_id' => 'pay_xxxxx',
    'amount' => 250,
]);

// Capture (when payment was authorized first)
$capture = $pay->capture([
    'payment_id' => 'pay_xxxxx',
    'amount' => 1000,
]);
```

Every method returns the same `PaymentResponse` object:

```php
$response->isSuccess();               // true / false
$response->getGatewayTransactionId(); // gateway payment / order id
$response->getMessage();              // human-readable message
$response->getData();                 // useful fields as array
$response->isRedirect();              // needs browser redirect?
$response->getRedirectUrl();          // URL to send the user to
```

## Webhooks (important)

Payzy registers this route for you:

```text
POST https://your-app.com/payzy/webhooks/{gateway}
```

Examples:

- `https://your-app.com/payzy/webhooks/razorpay`
- `https://your-app.com/payzy/webhooks/stripe`

### Setup checklist

1. Put the URL in your gateway dashboard (Razorpay / Stripe / etc.).
2. Put the webhook secret in `.env` (`RAZORPAY_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_SECRET`, …).
3. Run a queue worker so webhooks stay fast:

```bash
php artisan queue:work
```

Payzy will:

1. Verify the signature
2. Reject old or repeated events (replay protection)
3. Queue the work and return `202 Accepted`

## Avoid duplicate charges

Always send an idempotency key for checkout / create calls:

```php
Payzy::gateway('razorpay')
    ->amount(1000)
    ->currency('INR')
    ->orderId('ORDER-1001')
    ->idempotencyKey('checkout-ORDER-1001')
    ->create();
```

If the same key is reused with a **different** payload, Payzy throws `IdempotencyConflictException`.

## Listen for events

```php
use Sdpayhub\Payzy\Events\PaymentSuccess;

// In a listener:
public function handle(PaymentSuccess $event): void
{
    $gateway = $event->gateway;
    $txnId = $event->response->getGatewayTransactionId();

    // Mark the order as paid in your app
}
```

Useful events:

- `PaymentCreated`
- `PaymentSuccess`
- `PaymentFailed`
- `RefundCreated`
- `RefundCompleted`
- `WebhookReceived`
- `WebhookFailed`

## Handle errors

```php
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Facades\Payzy;

try {
    $response = Payzy::using('razorpay')->charge([
        'amount' => 1000,
        'currency' => 'INR',
        'order_id' => 'ORDER-1001',
    ]);
} catch (PayzyException $e) {
    // Safe to log — secrets are not in the message by design
    report($e);

    return back()->with('error', 'Payment could not be started.');
}
```

## Supported gateways

| Gateway | Payments | Refunds | Webhooks | Customers | Subscriptions |
|---|---|---|---|---|---|
| Razorpay | Yes | Yes | Yes | Yes | Yes |
| Stripe | Yes | Yes | Yes | Yes | Yes |
| PayPal | Yes | Yes | Yes | — | Yes |
| Paytm | Yes | Yes | Yes | — | — |
| PhonePe | Yes | Yes | Yes | — | — |

### Extra `.env` keys

```env
# Stripe
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

# PayPal
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=

# Paytm
PAYTM_MERCHANT_ID=
PAYTM_MERCHANT_KEY=

# PhonePe
PHONEPE_CLIENT_ID=
PHONEPE_CLIENT_SECRET=
PHONEPE_MERCHANT_ID=
PHONEPE_SALT_KEY=
```

## Security basics (please read)

1. Keep all secrets in `.env` — never hardcode keys in PHP files.
2. Use `PAYZY_MODE=sandbox` until you are ready for live traffic.
3. Always configure webhook secrets and keep verification enabled.
4. Serve webhook URLs over HTTPS only.
5. Run `php artisan queue:work` in production so webhook handlers are queued.
6. Do not disable SSL verification (Payzy never does this for you).

Report security issues privately — see [SECURITY.md](SECURITY.md).

## Testing this package

```bash
composer test
composer analyse
composer lint
```

## Need more detail?

- Upgrade notes: [UPGRADING.md](UPGRADING.md)
- How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changelog: [CHANGELOG.md](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE).
