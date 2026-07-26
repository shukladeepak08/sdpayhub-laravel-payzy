<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Facades;

use Illuminate\Support\Facades\Facade;
use Sdpayhub\Payzy\Services\PayzyManager;

/**
 * @method static \Sdpayhub\Payzy\Support\PendingPayment gateway(?string $name = null)
 * @method static \Sdpayhub\Payzy\Services\PayzyManager using(string $name)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse charge(array $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse create(array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse capture(array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse refund(string|array $paymentId, array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse partialRefund(array $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse status(string|array $paymentId, array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse cancel(array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verify(array $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verifySignature(array $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse verifyWebhook(array $payload = [])
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse paymentLink(array $payload)
 * @method static \Sdpayhub\Payzy\DTOs\PaymentResponse qr(array $payload)
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
