<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Illuminate\Support\Facades\DB;
use Sdpayhub\Payzy\Contracts\IdempotencyStore;
use Sdpayhub\Payzy\DTOs\PaymentResponse;

final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function get(string $key): ?PaymentResponse
    {
        $row = DB::table('payzy_idempotency_keys')->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        if (isset($row->expires_at) && now()->greaterThan($row->expires_at)) {
            $this->forget($key);

            return null;
        }

        $payload = json_decode((string) $row->response, true);

        if (! is_array($payload)) {
            return null;
        }

        return $this->hydrate($payload);
    }

    public function put(string $key, PaymentResponse $response, string $fingerprint, int $ttlSeconds): void
    {
        $expiresAt = now()->addSeconds($ttlSeconds);

        DB::table('payzy_idempotency_keys')->updateOrInsert(
            ['key' => $key],
            [
                'fingerprint' => $fingerprint,
                'response' => json_encode($response->toArray(), JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function getFingerprint(string $key): ?string
    {
        $row = DB::table('payzy_idempotency_keys')->where('key', $key)->first();

        return $row !== null && is_string($row->fingerprint ?? null) ? $row->fingerprint : null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        DB::table('payzy_idempotency_keys')->where('key', $key)->delete();
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
