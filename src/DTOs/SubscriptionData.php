<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\DTOs;

/**
 * Normalized subscription payload for gateways that support subscriptions.
 */
final class SubscriptionData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $planId = null,
        public readonly ?string $customerId = null,
        public readonly ?string $status = null,
        public readonly ?int $quantity = 1,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            planId: isset($data['plan_id']) ? (string) $data['plan_id'] : (isset($data['planId']) ? (string) $data['planId'] : null),
            customerId: isset($data['customer_id']) ? (string) $data['customer_id'] : (isset($data['customerId']) ? (string) $data['customerId'] : null),
            status: isset($data['status']) ? (string) $data['status'] : null,
            quantity: isset($data['quantity']) ? (int) $data['quantity'] : 1,
            meta: isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'plan_id' => $this->planId,
            'customer_id' => $this->customerId,
            'status' => $this->status,
            'quantity' => $this->quantity,
            'meta' => $this->meta === [] ? null : $this->meta,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
