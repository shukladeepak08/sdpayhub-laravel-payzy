<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Factories;

use Sdpayhub\Payzy\Contracts\GatewayInterface;
use Sdpayhub\Payzy\Exceptions\InvalidGatewayException;
use Sdpayhub\Payzy\Gateways\PayPal\PayPalGateway;
use Sdpayhub\Payzy\Gateways\Paytm\PaytmGateway;
use Sdpayhub\Payzy\Gateways\PhonePe\PhonePeGateway;
use Sdpayhub\Payzy\Gateways\Razorpay\RazorpayGateway;
use Sdpayhub\Payzy\Gateways\Stripe\StripeGateway;
use Sdpayhub\Payzy\Services\SecureHttpClient;

/**
 * Resolves gateway implementations by name.
 */
final class GatewayFactory
{
    /** @var array<string, class-string<GatewayInterface>> */
    private array $gateways = [
        'razorpay' => RazorpayGateway::class,
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
        'paytm' => PaytmGateway::class,
        'phonepe' => PhonePeGateway::class,
    ];

    public function __construct(
        private readonly SecureHttpClient $http,
    ) {}

    public function make(string $name): GatewayInterface
    {
        $name = strtolower(trim($name));

        if (! isset($this->gateways[$name])) {
            throw new InvalidGatewayException(
                message: sprintf('Gateway [%s] is not registered.', $name),
                context: ['gateway' => $name, 'available' => array_keys($this->gateways)],
            );
        }

        /** @var array<string, mixed> $all */
        $all = config('payzy', []);
        $gatewayConfig = $all['gateways'][$name] ?? null;

        if (! is_array($gatewayConfig)) {
            throw new InvalidGatewayException(
                message: sprintf('Gateway [%s] is missing configuration.', $name),
                context: ['gateway' => $name],
            );
        }

        $class = $this->gateways[$name];
        $mode = (string) ($all['mode'] ?? 'sandbox');

        return new $class($gatewayConfig, $this->http, $mode);
    }

    /**
     * @param  class-string<GatewayInterface>  $class
     */
    public function extend(string $name, string $class): void
    {
        $this->gateways[strtolower($name)] = $class;
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys($this->gateways);
    }
}
