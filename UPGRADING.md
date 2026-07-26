# Upgrading

## From 0.x / pre-release to 1.0

If you were evaluating early builds, treat **1.0.0** as the first stable public API.

1. Require `^1.0` in `composer.json`.
2. Publish (or re-publish) config:

   ```bash
   php artisan vendor:publish --tag=payzy-config --force
   ```

3. Confirm environment variables match `config/payzy.php` (notably `PAYZY_*` and per-gateway secrets).
4. Point provider webhook URLs at:

   ```text
   POST /payzy/webhooks/{gateway}
   ```

5. Ensure a queue worker is running if webhooks are enabled.
6. If you use the database idempotency driver, publish migrations and run `php artisan migrate`.
7. Update listeners to the event classes under `Sdpayhub\Payzy\Events\`.
8. Catch `Sdpayhub\Payzy\Exceptions\PayzyException` (or subclasses) instead of generic exceptions for payment flows.

## 1.x

Within the 1.x line, we aim for:

- No breaking changes to the `Payment` facade method signatures
- Stable event class names and constructor shapes
- Stable `PaymentResponse` public getters
- Additive gateway capabilities only

When a breaking change is required, it will ship as **2.0** with a dedicated upgrade guide in this file.

### Planned notes (placeholder)

Document future 1.x → 1.y migration steps here as they arise (config key renames, deprecated methods, gateway deprecations).

## Checking your upgrade

```bash
composer update sdpayhub/laravel-payzy
composer check
```

Run your application’s payment and webhook feature tests against sandbox credentials before switching `PAYZY_MODE` to `live`.
