<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Support;

/**
 * Masks secret values in arrays before logging.
 */
final class SecretMasker
{
    private const MASK = '********';

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private readonly array $keys = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mask(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->shouldMask($normalizedKey)) {
                $masked[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $masked[$key] = $this->mask($value);

                continue;
            }

            if (is_string($value) && $this->looksLikeSecret($value)) {
                $masked[$key] = self::MASK;

                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    public function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (strlen($value) <= 8) {
            return self::MASK;
        }

        return substr($value, 0, 4).self::MASK.substr($value, -4);
    }

    private function shouldMask(string $key): bool
    {
        foreach ($this->keys as $secretKey) {
            if ($key === strtolower($secretKey) || str_contains($key, strtolower($secretKey))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecret(string $value): bool
    {
        return (bool) preg_match('/^(sk_|rk_|whsec_|Bearer\s+)/i', $value);
    }
}
