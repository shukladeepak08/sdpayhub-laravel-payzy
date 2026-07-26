# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Nothing yet.

### Changed

- Nothing yet.

### Fixed

- Nothing yet.

### Deprecated

- Nothing yet.

### Removed

- Nothing yet.

### Security

- Nothing yet.

## [1.0.1] - 2026-07-26

### Fixed

- Idempotency no longer runs on read-only operations like `status()` / `verify()` (this broke fresh Laravel apps using `CACHE_STORE=database` without a database file)
- Clear `ConfigurationException` when the idempotency cache store is unavailable
- Default `PAYZY_IDEMPOTENCY_AUTO` is now `false` — pass an explicit `idempotencyKey()` for checkout protection (as documented)

### Changed

- README documents the `CACHE_STORE=file` recommendation for new apps

## [1.0.0] - 2026-07-26

### Added

- Unified `Payzy` facade and `PayzyManager` API for Razorpay, Stripe, PayPal, Paytm, and PhonePe
- Fluent pending payment builder: `Payzy::gateway()->amount()->currency()->orderId()->create()`
- Core operations: `charge` / `create`, `capture`, `refund`, `partialRefund`, `status`, `cancel`, `verify`, `verifySignature`, `verifyWebhook`, `paymentLink`, `qr`
- Customer APIs on Razorpay and Stripe (`SupportsCustomers`)
- Subscription APIs on Razorpay, Stripe, and PayPal (`SupportsSubscriptions`)
- Immutable `PaymentResponse` DTO for every gateway method
- Auto-registered webhook route `POST /payzy/webhooks/{gateway}` with signature verification, replay protection, and queued `ProcessWebhookJob`
- Idempotency service with cache and database drivers, optional auto-generated keys, and fingerprint conflict detection
- Domain events: `PaymentCreated`, `PaymentSuccess`, `PaymentFailed`, `RefundCreated`, `RefundCompleted`, `WebhookReceived`, `WebhookFailed`
- Exception hierarchy rooted at `PayzyException`
- `GatewayFactory::extend()` for custom gateways
- Secure HTTP client with timeouts, retries, jitter, and secret masking in logs
- Publishable config (`payzy.php`), webhook routes, and idempotency migration
- Pest test suite, PHPStan level max, Laravel Pint, and GitHub Actions CI matrix (PHP 8.1–8.3, Laravel 10–12)

[Unreleased]: https://github.com/sdpayhub/laravel-payzy/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sdpayhub/laravel-payzy/releases/tag/v1.0.0
