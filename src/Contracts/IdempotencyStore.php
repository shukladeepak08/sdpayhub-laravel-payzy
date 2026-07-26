<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Contracts;

use Sdpayhub\Payzy\DTOs\PaymentResponse;

/**
 * Stores idempotency keys and cached PaymentResponse payloads.
 */
interface IdempotencyStore
{
    public function get(string $key): ?PaymentResponse;

    public function put(string $key, PaymentResponse $response, string $fingerprint, int $ttlSeconds): void;

    public function getFingerprint(string $key): ?string;

    public function has(string $key): bool;

    public function forget(string $key): void;
}
