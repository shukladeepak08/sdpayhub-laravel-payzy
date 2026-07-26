<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Facades\Payzy;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('creates captures and refunds on stripe', function (): void {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_1',
            'amount' => 1000,
            'currency' => 'usd',
            'status' => 'requires_capture',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_1/capture' => Http::response([
            'id' => 'pi_1',
            'amount' => 1000,
            'currency' => 'usd',
            'status' => 'succeeded',
        ], 200),
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'payment_intent' => 'pi_1',
            'amount' => 500,
            'status' => 'succeeded',
            'currency' => 'usd',
        ], 200),
    ]);

    $create = Payzy::using('stripe')->charge([
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $capture = Payzy::using('stripe')->capture([
        'payment_id' => 'pi_1',
    ]);

    $refund = Payzy::using('stripe')->partialRefund([
        'payment_id' => 'pi_1',
        'amount' => 500,
    ]);

    expect($create)->toBeInstanceOf(PaymentResponse::class)
        ->and($create->getGatewayTransactionId())->toBe('pi_1')
        ->and($capture->isSuccess())->toBeTrue()
        ->and($refund->getGatewayTransactionId())->toBe('re_1');
});

it('manages stripe customers and subscriptions', function (): void {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1', 'email' => 'a@b.com'], 200),
        'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1', 'email' => 'a@b.com'], 200),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'status' => 'active'], 200),
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'active'], 200),
    ]);

    $customer = Payzy::using('stripe')->createCustomer(['email' => 'a@b.com', 'name' => 'Ada']);
    $fetched = Payzy::using('stripe')->getCustomer(['customer_id' => 'cus_1']);
    $subscription = Payzy::using('stripe')->createSubscription([
        'customer' => 'cus_1',
        'price_id' => 'price_1',
    ]);

    expect($customer->getGatewayTransactionId())->toBe('cus_1')
        ->and($fetched->isSuccess())->toBeTrue()
        ->and($subscription->getGatewayTransactionId())->toBe('sub_1');
});

it('verifies stripe webhooks with timestamp and rejects invalid signatures', function (): void {
    $payload = json_encode([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_1']],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();
    $signed = $timestamp.'.'.$payload;
    $secret = 'whsec_stripe_test';
    $sig = hash_hmac('sha256', $signed, $secret);
    $header = 't='.$timestamp.',v1='.$sig;

    $ok = Payzy::using('stripe')->verifyWebhook([
        'raw_body' => $payload,
        'signature' => $header,
        'payload' => json_decode($payload, true),
    ]);

    expect($ok->isSuccess())->toBeTrue()
        ->and($ok->getData()['event_id'])->toBe('evt_1');

    expect(fn () => Payzy::using('stripe')->verifyWebhook([
        'raw_body' => $payload,
        'signature' => 't='.$timestamp.',v1=bad',
        'payload' => json_decode($payload, true),
    ]))->toThrow(WebhookVerificationException::class);
});
