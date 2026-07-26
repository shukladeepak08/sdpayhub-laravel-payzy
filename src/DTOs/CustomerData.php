<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\DTOs;

/**
 * Normalized customer payload shared across gateways that support customers.
 */
final class CustomerData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : (isset($data['contact']) ? (string) $data['contact'] : null),
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'meta' => $this->meta === [] ? null : $this->meta,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
