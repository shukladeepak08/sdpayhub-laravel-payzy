<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Events\PaymentCreated;
use Sdpayhub\Payzy\Exceptions\ConfigurationException;
use Sdpayhub\Payzy\Exceptions\IdempotencyConflictException;
use Sdpayhub\Payzy\Exceptions\InvalidGatewayException;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Facades\Payzy;
use Sdpayhub\Payzy\Support\Signature;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('creates a razorpay order via the facade', function (): void {
    Event::fake([PaymentCreated::class]);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_ABC',
            'amount' => 1000,
            'currency' => 'INR',
            'status' => 'created',
        ], 200),
    ]);

    $response = Payzy::gateway('razorpay')
        ->amount(1000)
        ->currency('INR')
        ->orderId('ORDER001')
        ->create();

    expect($response)->toBeInstanceOf(PaymentResponse::class)
        ->and($response->isSuccess())->toBeTrue()
        ->and($response->getGatewayTransactionId())->toBe('order_ABC')
        ->and($response->getAmount())->toBe(1000)
        ->and($response->getCurrency())->toBe('INR');

    Event::assertDispatched(PaymentCreated::class);
});

it('captures refunds and checks status on razorpay', function (): void {
    Http::fake([
        'api.razorpay.com/v1/payments/pay_1/capture' => Http::response([
            'id' => 'pay_1',
            'amount' => 1000,
            'currency' => 'INR',
            'status' => 'captured',
        ], 200),
        'api.razorpay.com/v1/payments/pay_1/refund' => Http::response([
            'id' => 'rfnd_1',
            'payment_id' => 'pay_1',
            'amount' => 500,
            'status' => 'processed',
            'currency' => 'INR',
        ], 200),
        'api.razorpay.com/v1/payments/pay_1' => Http::response([
            'id' => 'pay_1',
            'amount' => 1000,
            'currency' => 'INR',
            'status' => 'captured',
        ], 200),
    ]);

    $capture = Payzy::using('razorpay')->capture([
        'payment_id' => 'pay_1',
        'amount' => 1000,
        'currency' => 'INR',
    ]);

    $refund = Payzy::using('razorpay')->partialRefund([
        'payment_id' => 'pay_1',
        'amount' => 500,
    ]);

    $status = Payzy::using('razorpay')->status('pay_1');

    expect($capture->isSuccess())->toBeTrue()
        ->and($refund->isSuccess())->toBeTrue()
        ->and($refund->getGatewayTransactionId())->toBe('rfnd_1')
        ->and($status->getStatus())->toBe('captured');
});

it('verifies razorpay payment signatures', function (): void {
    $orderId = 'order_1';
    $paymentId = 'pay_1';
    $signature = Signature::hmacSha256($orderId.'|'.$paymentId, 'rzp_test_secret');

    $response = Payzy::using('razorpay')->verifySignature([
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'signature' => $signature,
    ]);

    expect($response->isSuccess())->toBeTrue();

    $bad = Payzy::using('razorpay')->verifySignature([
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'signature' => 'invalid',
    ]);

    expect($bad->isSuccess())->toBeFalse();
});

it('verifies razorpay webhooks and rejects bad signatures', function (): void {
    $body = json_encode([
        'id' => 'evt_1',
        'event' => 'payment.captured',
        'created_at' => time(),
        'payload' => ['payment' => ['entity' => ['id' => 'pay_1']]],
    ], JSON_THROW_ON_ERROR);

    $signature = Signature::hmacSha256($body, 'whsec_test');

    $ok = Payzy::using('razorpay')->verifyWebhook([
        'raw_body' => $body,
        'signature' => $signature,
        'payload' => json_decode($body, true),
    ]);

    expect($ok->isSuccess())->toBeTrue()
        ->and($ok->getData()['event_id'])->toBe('evt_1');

    expect(fn () => Payzy::using('razorpay')->verifyWebhook([
        'raw_body' => $body,
        'signature' => 'bad',
        'payload' => json_decode($body, true),
    ]))->toThrow(WebhookVerificationException::class);
});

it('replays idempotent razorpay creates safely', function (): void {
    Http::fake([
        'api.razorpay.com/v1/orders' => Http::sequence()
            ->push(['id' => 'order_1', 'amount' => 1000, 'currency' => 'INR', 'status' => 'created'], 200)
            ->push(['id' => 'order_2', 'amount' => 1000, 'currency' => 'INR', 'status' => 'created'], 200),
    ]);

    $first = Payzy::using('razorpay')->charge([
        'amount' => 1000,
        'currency' => 'INR',
        'order_id' => 'ORD-1',
        'idempotency_key' => 'idem-1',
    ]);

    $second = Payzy::using('razorpay')->charge([
        'amount' => 1000,
        'currency' => 'INR',
        'order_id' => 'ORD-1',
        'idempotency_key' => 'idem-1',
    ]);

    expect($first->getGatewayTransactionId())->toBe('order_1')
        ->and($second->getGatewayTransactionId())->toBe('order_1');

    Http::assertSentCount(1);

    expect(fn () => Payzy::using('razorpay')->charge([
        'amount' => 2000,
        'currency' => 'INR',
        'order_id' => 'ORD-2',
        'idempotency_key' => 'idem-1',
    ]))->toThrow(IdempotencyConflictException::class);
});

it('creates customers and subscriptions on razorpay', function (): void {
    Http::fake([
        'api.razorpay.com/v1/customers' => Http::response(['id' => 'cust_1', 'email' => 'a@b.com'], 200),
        'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'status' => 'created'], 200),
    ]);

    $customer = Payzy::using('razorpay')->createCustomer([
        'name' => 'Ada',
        'email' => 'a@b.com',
        'phone' => '9999999999',
    ]);

    $subscription = Payzy::using('razorpay')->createSubscription([
        'plan_id' => 'plan_1',
        'total_count' => 12,
    ]);

    expect($customer->getGatewayTransactionId())->toBe('cust_1')
        ->and($subscription->getGatewayTransactionId())->toBe('sub_1');
});

it('creates payment links and qr codes', function (): void {
    Http::fake([
        'api.razorpay.com/v1/payment_links' => Http::response([
            'id' => 'plink_1',
            'short_url' => 'https://rzp.io/i/abc',
            'status' => 'created',
        ], 200),
        'api.razorpay.com/v1/payments/qr_codes' => Http::response([
            'id' => 'qr_1',
            'status' => 'active',
        ], 200),
    ]);

    $link = Payzy::using('razorpay')->paymentLink([
        'amount' => 1000,
        'currency' => 'INR',
    ]);

    $qr = Payzy::using('razorpay')->qr([
        'amount' => 1000,
        'currency' => 'INR',
    ]);

    expect($link->isRedirect())->toBeTrue()
        ->and($link->getRedirectUrl())->toBe('https://rzp.io/i/abc')
        ->and($qr->isSuccess())->toBeTrue();
});

it('throws for unknown gateways and missing credentials', function (): void {
    expect(fn () => Payzy::using('unknown')->charge(['amount' => 1, 'currency' => 'INR']))
        ->toThrow(InvalidGatewayException::class);

    config()->set('payzy.gateways.razorpay.secret', null);

    expect(fn () => Payzy::using('razorpay')->charge(['amount' => 1, 'currency' => 'INR']))
        ->toThrow(ConfigurationException::class);
});
