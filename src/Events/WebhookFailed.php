<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Events;

use Sdpayhub\Payzy\DTOs\WebhookPayload;

final class WebhookFailed
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $reason,
        public readonly ?WebhookPayload $payload = null,
    ) {}
}
