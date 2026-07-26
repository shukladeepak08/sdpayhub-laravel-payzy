<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\DTOs;

/**
 * Immutable unified response contract returned by every gateway method.
 */
final class PaymentResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        private readonly bool $success,
        private readonly array $data = [],
        private readonly array $rawResponse = [],
        private readonly ?string $gatewayTransactionId = null,
        private readonly ?string $message = null,
        private readonly bool $redirect = false,
        private readonly ?string $redirectUrl = null,
        private readonly ?string $status = null,
        private readonly ?int $amount = null,
        private readonly ?string $currency = null,
        private readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        array $data = [],
        array $rawResponse = [],
        ?string $gatewayTransactionId = null,
        ?string $message = null,
        ?string $status = null,
        ?int $amount = null,
        ?string $currency = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            data: $data,
            rawResponse: $rawResponse,
            gatewayTransactionId: $gatewayTransactionId,
            message: $message ?? 'Payment operation succeeded.',
            redirect: false,
            redirectUrl: null,
            status: $status ?? 'success',
            amount: $amount,
            currency: $currency,
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $meta
     */
    public static function failure(
        ?string $message = null,
        array $data = [],
        array $rawResponse = [],
        ?string $gatewayTransactionId = null,
        ?string $status = null,
        ?int $amount = null,
        ?string $currency = null,
        array $meta = [],
    ): self {
        return new self(
            success: false,
            data: $data,
            rawResponse: $rawResponse,
            gatewayTransactionId: $gatewayTransactionId,
            message: $message ?? 'Payment operation failed.',
            redirect: false,
            redirectUrl: null,
            status: $status ?? 'failed',
            amount: $amount,
            currency: $currency,
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $meta
     */
    public static function redirect(
        string $redirectUrl,
        array $data = [],
        array $rawResponse = [],
        ?string $gatewayTransactionId = null,
        ?string $message = null,
        ?string $status = null,
        ?int $amount = null,
        ?string $currency = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            data: $data,
            rawResponse: $rawResponse,
            gatewayTransactionId: $gatewayTransactionId,
            message: $message ?? 'Redirect required to complete payment.',
            redirect: true,
            redirectUrl: $redirectUrl,
            status: $status ?? 'pending',
            amount: $amount,
            currency: $currency,
            meta: $meta,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    public function getGatewayTransactionId(): ?string
    {
        return $this->gatewayTransactionId;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isRedirect(): bool
    {
        return $this->redirect;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'raw_response' => $this->rawResponse,
            'gateway_transaction_id' => $this->gatewayTransactionId,
            'message' => $this->message,
            'redirect' => $this->redirect,
            'redirect_url' => $this->redirectUrl,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'meta' => $this->meta,
        ];
    }
}
