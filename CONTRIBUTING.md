# Contributing

Thank you for considering contributing to Payzy.

## Code of conduct

Be respectful and constructive. Harassment or personal attacks are not acceptable.

## Development setup

1. Fork and clone the repository.
2. Install dependencies:

   ```bash
   composer install
   ```

3. Copy any local env you need for manual gateway smoke tests. Automated tests should not require live credentials.

## Quality bar

Before opening a pull request, run:

```bash
composer check
```

That runs Pint (`composer lint`), PHPStan level max (`composer analyse`), and Pest (`composer test`).

Coverage target for the suite is **95%+** (`composer test:coverage`).

## Pull requests

- Keep changes focused; one concern per PR when practical
- Match existing code style: `declare(strict_types=1);`, final classes where appropriate, typed properties and returns
- Every gateway method must return `PaymentResponse` — never bool, raw arrays, or strings as the public result
- Prefer Http::fake() feature tests over live API calls
- Update README / CHANGELOG when you change public API behavior
- Do not commit secrets, vendor artifacts, or IDE metadata

### New gateways

1. Implement `GatewayInterface` (extend `AbstractGateway` when possible).
2. Register the default mapping in `GatewayFactory` only if the gateway ships with the package.
3. Add config keys under `config/payzy.php`.
4. Cover create, refund/status (as applicable), and webhook verification with Pest tests.
5. Document the gateway in the README supported-gateways table.

### Custom app gateways

App-level gateways should use `GatewayFactory::extend()` and stay out of the package core unless they are generally useful and maintained here.

## Commit messages

Use clear, imperative subjects:

- `Add PhonePe webhook timestamp tolerance`
- `Fix Stripe idempotency fingerprint collision`
- `Document PayPal subscription cancel payload`

## Security issues

Do not open public issues for vulnerabilities. Follow [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
