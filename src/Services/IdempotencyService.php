<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Illuminate\Support\Str;
use Sdpayhub\Payzy\Contracts\IdempotencyStore;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\IdempotencyConflictException;

/**
 * Handles idempotency key generation, fingerprinting, and replay-safe caching.
 */
final class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function resolveKey(?string $provided): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($provided !== null && $provided !== '') {
            return $provided;
        }

        if (! (bool) ($this->config['auto_generate'] ?? true)) {
            return null;
        }

        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(string $operation, array $payload): string
    {
        $normalized = $payload;
        unset($normalized['idempotency_key']);

        return hash('sha256', $operation.'|'.json_encode($this->ksortRecursive($normalized)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rememberOrExecute(string $key, string $operation, array $payload, callable $callback): PaymentResponse
    {
        if (! $this->enabled()) {
            /** @var PaymentResponse $response */
            $response = $callback();

            return $response;
        }

        $fingerprint = $this->fingerprint($operation, $payload);

        if ($this->store->has($key)) {
            $existingFingerprint = $this->store->getFingerprint($key);

            if ($existingFingerprint !== null && ! hash_equals($existingFingerprint, $fingerprint)) {
                throw new IdempotencyConflictException(
                    message: 'Idempotency key reused with a different request payload.',
                    context: ['key' => $key, 'operation' => $operation],
                );
            }

            $cached = $this->store->get($key);

            if ($cached !== null) {
                return $cached;
            }
        }

        /** @var PaymentResponse $response */
        $response = $callback();

        $ttl = (int) ($this->config['ttl_seconds'] ?? 86400);
        $this->store->put($key, $response, $fingerprint, $ttl);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ksortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = $this->ksortRecursive($value);
            }
        }

        ksort($data);

        return $data;
    }
}
