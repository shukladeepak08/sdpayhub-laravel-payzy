<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Support;

use Sdpayhub\Payzy\DTOs\CustomerData;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Services\PayzyManager;

/**
 * Fluent pending payment builder used by the Payment facade/manager.
 */
final class PendingPayment
{
    private ?string $gateway = null;

    private ?int $amount = null;

    private ?string $currency = null;

    private ?string $orderId = null;

    private ?CustomerData $customer = null;

    private ?string $idempotencyKey = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(
        private readonly PayzyManager $manager,
    ) {}

    public function gateway(string $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function amount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function orderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * @param  CustomerData|array<string, mixed>  $customer
     */
    public function customer(CustomerData|array $customer): self
    {
        $this->customer = $customer instanceof CustomerData
            ? $customer
            : CustomerData::fromArray($customer);

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->orderId,
            'customer' => $this->customer?->toArray(),
            'idempotency_key' => $this->idempotencyKey,
            'meta' => $this->meta === [] ? null : $this->meta,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function create(): PaymentResponse
    {
        return $this->manager->createFromPending($this);
    }
}
