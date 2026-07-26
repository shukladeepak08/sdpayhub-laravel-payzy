<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways;

use Sdpayhub\Payzy\Contracts\GatewayInterface;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\ConfigurationException;
use Sdpayhub\Payzy\Exceptions\PaymentFailedException;
use Sdpayhub\Payzy\Services\SecureHttpClient;

/**
 * Shared helpers for concrete gateway implementations.
 */
abstract class AbstractGateway implements GatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
        protected readonly SecureHttpClient $http,
        protected readonly string $mode = 'sandbox',
    ) {
        $this->validateConfig();
    }

    abstract public function getName(): string;

    /**
     * @return list<string>
     */
    abstract protected function requiredConfigKeys(): array;

    protected function validateConfig(): void
    {
        foreach ($this->requiredConfigKeys() as $key) {
            $value = $this->config[$key] ?? null;

            if ($value === null || $value === '') {
                throw new ConfigurationException(
                    message: sprintf('Missing required configuration [%s] for gateway [%s].', $key, $this->getName()),
                    context: ['gateway' => $this->getName(), 'key' => $key],
                );
            }
        }
    }

    protected function baseUrl(): string
    {
        $url = $this->config['base_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new ConfigurationException(
                message: sprintf('Missing base_url for gateway [%s].', $this->getName()),
                context: ['gateway' => $this->getName()],
            );
        }

        return rtrim($url, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function requireKeys(array $payload, string ...$keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                throw new PaymentFailedException(
                    message: sprintf('Missing required field [%s] for gateway [%s].', $key, $this->getName()),
                    context: ['gateway' => $this->getName(), 'field' => $key],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $raw
     */
    protected function mapSuccess(
        array $body,
        array $raw,
        ?string $transactionId = null,
        ?string $message = null,
        ?string $status = null,
        ?int $amount = null,
        ?string $currency = null,
    ): PaymentResponse {
        return PaymentResponse::success(
            data: $body,
            rawResponse: $raw,
            gatewayTransactionId: $transactionId,
            message: $message,
            status: $status,
            amount: $amount,
            currency: $currency,
            meta: ['gateway' => $this->getName(), 'mode' => $this->mode],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $raw
     */
    protected function mapFailure(
        string $message,
        array $body = [],
        array $raw = [],
        ?string $transactionId = null,
        ?string $status = null,
    ): PaymentResponse {
        return PaymentResponse::failure(
            message: $message,
            data: $body,
            rawResponse: $raw,
            gatewayTransactionId: $transactionId,
            status: $status,
            meta: ['gateway' => $this->getName(), 'mode' => $this->mode],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $raw
     */
    protected function mapRedirect(
        string $url,
        array $body = [],
        array $raw = [],
        ?string $transactionId = null,
        ?int $amount = null,
        ?string $currency = null,
    ): PaymentResponse {
        return PaymentResponse::redirect(
            redirectUrl: $url,
            data: $body,
            rawResponse: $raw,
            gatewayTransactionId: $transactionId,
            amount: $amount,
            currency: $currency,
            meta: ['gateway' => $this->getName(), 'mode' => $this->mode],
        );
    }

    protected function configString(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ConfigurationException(
                message: sprintf('Invalid configuration [%s] for gateway [%s].', $key, $this->getName()),
                context: ['gateway' => $this->getName(), 'key' => $key],
            );
        }

        return $value;
    }
}
