# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |
| &lt; 1.0 | No |

## Reporting a vulnerability

If you find a security issue in Payzy, **report it privately**.

**Do not** open a public GitHub issue for anything that could:

- Leak merchant API keys or webhook secrets
- Forge or replay webhooks
- Bypass idempotency and create duplicate charges
- Skip signature verification

**Email:** dshukla0806@gmail.com

Please include:

1. Package version (`composer show sdpayhub/laravel-payzy`)
2. PHP and Laravel versions
3. What is wrong and what an attacker could do
4. Steps to reproduce (or a small proof of concept)
5. A suggested fix, if you have one

You should get an acknowledgement within **72 hours**. We will work with you on a fix and disclosure timeline.

## Security standards in this package

Payzy is built with these rules:

| Control | How Payzy handles it |
|---|---|
| Secrets | Read from environment / config only — never hard-coded |
| Logging | API keys, tokens, and card-like fields are masked |
| HTTP | Laravel HTTP client with TLS verification always on |
| Webhooks | Real per-gateway signature verification |
| Replay attacks | Timestamp window + event-id / nonce dedupe |
| Idempotency | Fingerprinted keys; conflicting payloads are rejected |
| Errors | Typed exceptions under `PayzyException` (no silent swallow of failures) |
| Dependencies | Dependabot alerts enabled via `.github/dependabot.yml` |

## Maintainer checklist before a release

- [ ] No secrets in the repository (`.env`, keys, tokens)
- [ ] CI green (lint, PHPStan, tests)
- [ ] Webhook verification paths covered by tests
- [ ] Idempotency conflict behaviour covered by tests
- [ ] `SECURITY.md` and contact email still valid

## Scope

**In scope**

- Webhook signature bypass
- Replay / nonce guard weaknesses
- Secret leakage through logs or responses
- Idempotency races that can double-charge
- Unsafe HTTP client behaviour that hurts payment integrity

**Out of scope**

- Stolen or leaked merchant keys outside this package
- Bugs in Razorpay / Stripe / PayPal / Paytm / PhonePe APIs
- Application-level auth / authorization in your own app
- Denial of service against your own infrastructure

## Safe harbour

Good-faith security research (no data destruction, no privacy harm beyond what is needed to show the issue) will not face legal action from the maintainer for reports sent through this process.
