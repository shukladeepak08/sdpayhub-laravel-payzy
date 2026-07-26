<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Sdpayhub\Payzy\Contracts\IdempotencyStore;
use Sdpayhub\Payzy\DTOs\PaymentResponse;

final class CacheIdempotencyStore implements IdempotencyStore
{
    private const PREFIX = 'payzy:idempotency:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function get(string $key): ?PaymentResponse
    {
        $payload = $this->cache->get($this->responseKey($key));

        if (! is_array($payload)) {
            return null;
        }

        return $this->hydrate($payload);
    }

    public function put(string $key, PaymentResponse $response, string $fingerprint, int $ttlSeconds): void
    {
        $this->cache->put($this->responseKey($key), $response->toArray(), $ttlSeconds);
        $this->cache->put($this->fingerprintKey($key), $fingerprint, $ttlSeconds);
    }

    public function getFingerprint(string $key): ?string
    {
        $value = $this->cache->get($this->fingerprintKey($key));

        return is_string($value) ? $value : null;
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->responseKey($key));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->responseKey($key));
        $this->cache->forget($this->fingerprintKey($key));
    }

    private function responseKey(string $key): string
    {
        return self::PREFIX.'response:'.$key;
    }

    private function fingerprintKey(string $key): string
    {
        return self::PREFIX.'fingerprint:'.$key;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrate(array $payload): PaymentResponse
    {
        if (($payload['redirect'] ?? false) === true && isset($payload['redirect_url']) && is_string($payload['redirect_url'])) {
            return PaymentResponse::redirect(
                redirectUrl: $payload['redirect_url'],
                data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
                rawResponse: is_array($payload['raw_response'] ?? null) ? $payload['raw_response'] : [],
                gatewayTransactionId: isset($payload['gateway_transaction_id']) ? (string) $payload['gateway_transaction_id'] : null,
                message: isset($payload['message']) ? (string) $payload['message'] : null,
                status: isset($payload['status']) ? (string) $payload['status'] : null,
                amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
                currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
                meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            );
        }

        if (($payload['success'] ?? false) === true) {
            return PaymentResponse::success(
                data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
                rawResponse: is_array($payload['raw_response'] ?? null) ? $payload['raw_response'] : [],
                gatewayTransactionId: isset($payload['gateway_transaction_id']) ? (string) $payload['gateway_transaction_id'] : null,
                message: isset($payload['message']) ? (string) $payload['message'] : null,
                status: isset($payload['status']) ? (string) $payload['status'] : null,
                amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
                currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
                meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            );
        }

        return PaymentResponse::failure(
            message: isset($payload['message']) ? (string) $payload['message'] : 'Cached failure response.',
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            rawResponse: is_array($payload['raw_response'] ?? null) ? $payload['raw_response'] : [],
            gatewayTransactionId: isset($payload['gateway_transaction_id']) ? (string) $payload['gateway_transaction_id'] : null,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        );
    }
}
