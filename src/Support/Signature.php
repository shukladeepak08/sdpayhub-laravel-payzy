<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Support;

/**
 * Timing-safe signature helpers.
 */
final class Signature
{
    public static function hmacSha256(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function hmacSha256Hex(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function equals(string $known, string $user): bool
    {
        if ($known === '' || $user === '') {
            return false;
        }

        return hash_equals($known, $user);
    }
}
