# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |
| Pre-1.0 | No |

## Reporting a vulnerability

If you discover a security issue in Payzy, please report it privately.

**Do not** open a public GitHub issue for vulnerabilities that could expose merchant credentials, forge webhooks, bypass idempotency, or cause duplicate charges.

Email: **shukladeepak08@gmail.com**

Include:

- Package version (`composer show sdpayhub/laravel-payzy`)
- Laravel and PHP versions
- A clear description of the issue and impact
- Steps to reproduce or a minimal proof of concept
- Any suggested fix, if you have one

You should receive an acknowledgement within **72 hours**. We will coordinate a fix and disclosure timeline with you.

## Scope

In scope:

- Webhook signature verification bypass
- Replay / nonce guard weaknesses
- Secret leakage via logs or responses
- Idempotency store races that can double-charge
- Unsafe HTTP client behaviour (timeouts, redirects) that affects payment integrity

Out of scope:

- Compromised merchant API keys or misconfigured provider dashboards
- Issues in upstream payment provider APIs
- Denial of service against your own application infrastructure

## Safe harbour

Security researchers acting in good faith (no data destruction, no privacy violations beyond what is needed to demonstrate the issue) will not face legal action from the maintainer for reports submitted through this process.
