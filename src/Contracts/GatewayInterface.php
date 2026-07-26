<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Contracts;

use Sdpayhub\Payzy\DTOs\PaymentResponse;

/**
 * Contract every payment gateway must implement.
 *
 * Every method MUST return PaymentResponse — never bool, array, or string.
 */
interface GatewayInterface
{
    /**
     * Create a payment / order.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): PaymentResponse;

    /**
     * Capture an authorized payment.
     *
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload): PaymentResponse;

    /**
     * Refund a payment in full.
     *
     * @param  array<string, mixed>  $payload
     */
    public function refund(array $payload): PaymentResponse;

    /**
     * Partially refund a payment.
     *
     * @param  array<string, mixed>  $payload
     */
    public function partialRefund(array $payload): PaymentResponse;

    /**
     * Fetch payment / order status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function status(array $payload): PaymentResponse;

    /**
     * Cancel a payment / order when supported.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancel(array $payload): PaymentResponse;

    /**
     * Verify a completed payment against the gateway.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): PaymentResponse;

    /**
     * Verify a client-side payment signature.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): PaymentResponse;

    /**
     * Verify an inbound webhook request (signature + payload integrity).
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhook(array $payload): PaymentResponse;

    /**
     * Generate a payment link.
     *
     * @param  array<string, mixed>  $payload
     */
    public function paymentLink(array $payload): PaymentResponse;

    /**
     * Generate a QR payment when supported.
     *
     * @param  array<string, mixed>  $payload
     */
    public function qr(array $payload): PaymentResponse;

    /**
     * Gateway identifier used in factory registration.
     */
    public function getName(): string;
}
