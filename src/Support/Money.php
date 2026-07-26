<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Support;

/**
 * Minor-unit money helper (amounts are integers in the smallest currency unit).
 */
final class Money
{
    public function __construct(
        private readonly int $amount,
        private readonly string $currency,
    ) {}

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    public function toMajorUnits(): float
    {
        return $this->amount / 100;
    }

    public static function fromMajor(float|int|string $amount, string $currency): self
    {
        return new self((int) round(((float) $amount) * 100), strtoupper($currency));
    }
}
