<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Facades;

use Illuminate\Support\Facades\Facade;
use Sdpayhub\Payzy\Services\PayzyManager;

/**
 * @method static \Sdpayhub\Payzy\Support\PendingPayment gateway(?string $name = null)
 * @method static \Sdpayhub\Payzy\Services\PayzyManager using(string $name)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse charge(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse create(array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse capture(array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse refund(string|array<string, mixed> $paymentId, array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse partialRefund(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse status(string|array<string, mixed> $paymentId, array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse cancel(array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verify(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verifySignature(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verifyWebhook(array<string, mixed> $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse paymentLink(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse qr(array<string, mixed> $payload)
 * @method static \Sdpayhub\Payzy\Contracts\GatewayInterface getGatewayInstance(?string $name = null)
 *
 * @see PayzyManager
 */
final class Payzy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PayzyManager::class;
    }
}
