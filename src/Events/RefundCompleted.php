<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Events;

use Sdpayhub\Payzy\DTOs\PaymentResponse;

final class RefundCompleted
{
    public function __construct(
        public readonly string $gateway,
        public readonly PaymentResponse $response,
    ) {}
}
