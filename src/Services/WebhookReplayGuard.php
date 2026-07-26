<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;

/**
 * Protects against webhook replay attacks via timestamp window + nonce/event-id dedupe.
 */
final class WebhookReplayGuard
{
    private const PREFIX = 'payzy:webhook_nonce:';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $timestampToleranceSeconds,
        private readonly int $nonceTtlSeconds,
    ) {}

    public function assertFresh(?int $timestamp, string $eventId, string $gateway): void
    {
        if ($timestamp !== null) {
            $now = time();
            $delta = abs($now - $timestamp);

            if ($delta > $this->timestampToleranceSeconds) {
                throw new WebhookVerificationException(
                    message: 'Webhook timestamp is outside the allowed tolerance window.',
                    context: [
                        'gateway' => $gateway,
                        'timestamp' => $timestamp,
                        'tolerance' => $this->timestampToleranceSeconds,
                        'delta' => $delta,
                    ],
                );
            }
        }

        if ($eventId === '') {
            throw new WebhookVerificationException(
                message: 'Webhook event id is required for replay protection.',
                context: ['gateway' => $gateway],
            );
        }

        $cacheKey = self::PREFIX.$gateway.':'.$eventId;

        if ($this->cache->has($cacheKey)) {
            throw new WebhookVerificationException(
                message: 'Webhook event has already been processed (replay detected).',
                context: ['gateway' => $gateway, 'event_id' => $eventId],
            );
        }

        $this->cache->put($cacheKey, true, $this->nonceTtlSeconds);
    }
}
