<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Sdpayhub\Payzy\Contracts\GatewayInterface;
use Sdpayhub\Payzy\Contracts\SupportsCustomers;
use Sdpayhub\Payzy\Contracts\SupportsSubscriptions;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Events\PaymentCreated;
use Sdpayhub\Payzy\Events\PaymentFailed;
use Sdpayhub\Payzy\Events\PaymentSuccess;
use Sdpayhub\Payzy\Events\RefundCompleted;
use Sdpayhub\Payzy\Events\RefundCreated;
use Sdpayhub\Payzy\Exceptions\ConfigurationException;
use Sdpayhub\Payzy\Exceptions\IdempotencyConflictException;
use Sdpayhub\Payzy\Exceptions\PaymentFailedException;
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Factories\GatewayFactory;
use Sdpayhub\Payzy\Support\PendingPayment;
use Throwable;

/**
 * Primary entry-point for the unified payment API.
 */
final class PayzyManager
{
    private ?string $gatewayName = null;

    private ?GatewayInterface $gateway = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly GatewayFactory $factory,
        private readonly IdempotencyService $idempotency,
        private readonly array $config,
    ) {}

    public function gateway(?string $name = null): PendingPayment
    {
        $pending = new PendingPayment($this);

        if ($name !== null && $name !== '') {
            $this->using($name);
            $pending->gateway($name);
        }

        return $pending;
    }

    public function using(string $name): self
    {
        $this->gatewayName = $name;
        $this->gateway = $this->factory->make($name);

        return $this;
    }

    public function getGatewayInstance(?string $name = null): GatewayInterface
    {
        if ($name !== null) {
            return $this->factory->make($name);
        }

        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $default = (string) ($this->config['default'] ?? 'razorpay');

        return $this->factory->make($default);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function charge(array $payload): PaymentResponse
    {
        return $this->execute('create', $payload, function (GatewayInterface $gateway, array $data): PaymentResponse {
            return $gateway->create($data);
        }, PaymentCreated::class, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload = []): PaymentResponse
    {
        return $this->charge($payload);
    }

    public function createFromPending(PendingPayment $pending): PaymentResponse
    {
        if ($pending->getGateway() !== null) {
            $this->using($pending->getGateway());
        }

        return $this->charge($pending->toPayload());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload = []): PaymentResponse
    {
        return $this->execute('capture', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->capture($d), PaymentSuccess::class);
    }

    /**
     * @param  string|array<string, mixed>  $paymentId
     * @param  array<string, mixed>  $payload
     */
    public function refund(string|array $paymentId, array $payload = []): PaymentResponse
    {
        if (is_string($paymentId)) {
            $payload['payment_id'] = $paymentId;
        } else {
            $payload = $paymentId;
        }

        return $this->execute('refund', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->refund($d), RefundCreated::class, false, RefundCompleted::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function partialRefund(array $payload): PaymentResponse
    {
        return $this->execute('partial_refund', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->partialRefund($d), RefundCreated::class, false, RefundCompleted::class);
    }

    /**
     * @param  string|array<string, mixed>  $paymentId
     * @param  array<string, mixed>  $payload
     */
    public function status(string|array $paymentId, array $payload = []): PaymentResponse
    {
        if (is_string($paymentId)) {
            $payload['payment_id'] = $paymentId;
        } else {
            $payload = $paymentId;
        }

        return $this->execute('status', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->status($d));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(array $payload = []): PaymentResponse
    {
        return $this->execute('cancel', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->cancel($d));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): PaymentResponse
    {
        return $this->execute('verify', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->verify($d), PaymentSuccess::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): PaymentResponse
    {
        return $this->execute('verify_signature', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->verifySignature($d));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhook(array $payload = []): PaymentResponse
    {
        return $this->execute('verify_webhook', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->verifyWebhook($d), null, false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paymentLink(array $payload): PaymentResponse
    {
        return $this->execute('payment_link', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->paymentLink($d), PaymentCreated::class, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function qr(array $payload): PaymentResponse
    {
        return $this->execute('qr', $payload, fn (GatewayInterface $g, array $d): PaymentResponse => $g->qr($d), PaymentCreated::class, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCustomer(array $payload): PaymentResponse
    {
        return $this->executeCapability('create_customer', $payload, SupportsCustomers::class, 'createCustomer');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function getCustomer(array $payload): PaymentResponse
    {
        return $this->executeCapability('get_customer', $payload, SupportsCustomers::class, 'getCustomer');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCustomer(array $payload): PaymentResponse
    {
        return $this->executeCapability('update_customer', $payload, SupportsCustomers::class, 'updateCustomer');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteCustomer(array $payload): PaymentResponse
    {
        return $this->executeCapability('delete_customer', $payload, SupportsCustomers::class, 'deleteCustomer');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSubscription(array $payload): PaymentResponse
    {
        return $this->executeCapability('create_subscription', $payload, SupportsSubscriptions::class, 'createSubscription');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function getSubscription(array $payload): PaymentResponse
    {
        return $this->executeCapability('get_subscription', $payload, SupportsSubscriptions::class, 'getSubscription');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancelSubscription(array $payload): PaymentResponse
    {
        return $this->executeCapability('cancel_subscription', $payload, SupportsSubscriptions::class, 'cancelSubscription');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pauseSubscription(array $payload): PaymentResponse
    {
        return $this->executeCapability('pause_subscription', $payload, SupportsSubscriptions::class, 'pauseSubscription');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resumeSubscription(array $payload): PaymentResponse
    {
        return $this->executeCapability('resume_subscription', $payload, SupportsSubscriptions::class, 'resumeSubscription');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(GatewayInterface, array<string, mixed>): PaymentResponse  $callback
     * @param  class-string|null  $successEvent
     * @param  class-string|null  $completedEvent
     */
    private function execute(
        string $operation,
        array $payload,
        callable $callback,
        ?string $successEvent = null,
        bool $createdEvent = false,
        ?string $completedEvent = null,
    ): PaymentResponse {
        $gateway = $this->getGatewayInstance();
        $gatewayName = $gateway->getName();

        if (! isset($payload['currency']) || $payload['currency'] === null || $payload['currency'] === '') {
            $payload['currency'] = (string) config('payzy.currency', $this->config['currency'] ?? 'INR');
        }

        $providedIdempotencyKey = isset($payload['idempotency_key']) && is_string($payload['idempotency_key'])
            ? $payload['idempotency_key']
            : null;

        // Idempotency applies only to mutating payment operations — never to status/verify reads.
        $idempotencyKey = $this->usesIdempotency($operation)
            ? $this->idempotency->resolveKey($providedIdempotencyKey)
            : (($providedIdempotencyKey !== null && $providedIdempotencyKey !== '') ? $providedIdempotencyKey : null);

        if ($idempotencyKey !== null && $this->usesIdempotency($operation)) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        try {
            if ($idempotencyKey !== null && $this->usesIdempotency($operation)) {
                try {
                    $response = $this->idempotency->rememberOrExecute(
                        $idempotencyKey,
                        $gatewayName.':'.$operation,
                        $payload,
                        static function () use ($callback, $gateway, $payload): PaymentResponse {
                            return $callback($gateway, $payload);
                        },
                    );
                } catch (ConfigurationException $exception) {
                    // Explicit keys must not silently skip protection; auto keys may fall through.
                    if ($providedIdempotencyKey !== null && $providedIdempotencyKey !== '') {
                        throw $exception;
                    }

                    $response = $callback($gateway, $payload);
                }
            } else {
                $response = $callback($gateway, $payload);
            }
        } catch (PayzyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PaymentFailedException(
                message: 'Payment operation failed: '.$exception->getMessage(),
                previous: $exception,
                context: ['gateway' => $gatewayName, 'operation' => $operation],
            );
        }

        if ($createdEvent && $successEvent !== null) {
            event(new $successEvent($gatewayName, $response));
        } elseif ($successEvent !== null && $response->isSuccess()) {
            event(new $successEvent($gatewayName, $response));
        }

        if ($completedEvent !== null && $response->isSuccess()) {
            event(new $completedEvent($gatewayName, $response));
        }

        if (! $response->isSuccess() && in_array($operation, ['create', 'capture', 'verify'], true)) {
            event(new PaymentFailed($gatewayName, $response));
        }

        return $response;
    }

    /**
     * Operations that may safely use idempotency keys.
     */
    private function usesIdempotency(string $operation): bool
    {
        return in_array($operation, [
            'create',
            'capture',
            'refund',
            'partial_refund',
            'payment_link',
            'qr',
            'create_customer',
            'update_customer',
            'delete_customer',
            'create_subscription',
            'cancel_subscription',
            'pause_subscription',
            'resume_subscription',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string  $interface
     */
    private function executeCapability(string $operation, array $payload, string $interface, string $method): PaymentResponse
    {
        $gateway = $this->getGatewayInstance();

        if (! $gateway instanceof $interface) {
            throw new ConfigurationException(
                message: sprintf('Gateway [%s] does not support [%s].', $gateway->getName(), $operation),
                context: ['gateway' => $gateway->getName(), 'operation' => $operation],
            );
        }

        return $this->execute($operation, $payload, static function (GatewayInterface $g, array $d) use ($method): PaymentResponse {
            /** @var callable $callable */
            $callable = [$g, $method];

            return $callable($d);
        });
    }
}
