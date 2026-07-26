<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\DTOs;

/**
 * Normalized webhook payload after verification.
 */
final class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly ?int $timestamp = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'timestamp' => $this->timestamp,
        ];
    }
}
