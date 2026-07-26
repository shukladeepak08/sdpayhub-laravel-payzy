<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Contracts;

use Sdpayhub\Payzy\DTOs\PaymentResponse;

/**
 * Optional capability for gateways that support customers.
 */
interface SupportsCustomers
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCustomer(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function getCustomer(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCustomer(array $payload): PaymentResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteCustomer(array $payload): PaymentResponse;
}
