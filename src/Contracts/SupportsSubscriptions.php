<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Contracts;

use Sdpayhub\Payzy\DTOs\PaymentResponse;

/**
 * Optional capability for gateways that support subscriptions.
 */
interface SupportsSubscriptions
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSubscription(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function getSubscription(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancelSubscription(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pauseSubscription(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resumeSubscription(array $payload): PaymentResponse;
}
