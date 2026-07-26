<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Events;

use Sdpayhub\Payzy\DTOs\WebhookPayload;

final class WebhookReceived
{
    public function __construct(
        public readonly string $gateway,
        public readonly WebhookPayload $payload,
    ) {}
}
