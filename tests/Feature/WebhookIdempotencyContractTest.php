<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Events\WebhookReceived;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Facades\Payzy;
use Sdpayhub\Payzy\Jobs\ProcessWebhookJob;
use Sdpayhub\Payzy\Services\PayzyManager;
use Sdpayhub\Payzy\Services\WebhookReplayGuard;
use Sdpayhub\Payzy\Support\SecretMasker;
use Sdpayhub\Payzy\Support\Signature;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('rejects replayed webhook event ids', function (): void {
    /** @var WebhookReplayGuard $guard */
    $guard = app(WebhookReplayGuard::class);

    $guard->assertFresh(time(), 'evt_unique_1', 'razorpay');

    expect(fn () => $guard->assertFresh(time(), 'evt_unique_1', 'razorpay'))
        ->toThrow(WebhookVerificationException::class);
});

it('rejects webhooks outside the timestamp window', function (): void {
    /** @var WebhookReplayGuard $guard */
    $guard = app(WebhookReplayGuard::class);

    expect(fn () => $guard->assertFresh(time() - 10_000, 'evt_old', 'razorpay'))
        ->toThrow(WebhookVerificationException::class);
});

it('accepts verified razorpay webhooks through the HTTP endpoint and queues processing', function (): void {
    Event::fake([WebhookReceived::class]);
    Queue::fake();

    $body = [
        'id' => 'evt_http_1',
        'event' => 'payment.captured',
        'created_at' => time(),
        'payload' => ['payment' => ['entity' => ['id' => 'pay_1']]],
    ];

    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = Signature::hmacSha256($raw, 'test_razorpay_webhook_secret');

    $response = $this->call(
        'POST',
        '/payzy/webhooks/razorpay',
        $body,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ],
        $raw,
    );

    $response->assertStatus(202);
    Event::assertDispatched(WebhookReceived::class);
    Queue::assertPushed(ProcessWebhookJob::class);
});

it('returns the same PaymentResponse contract for every gateway create operation', function (): void {
    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_rzp',
            'amount' => 1000,
            'currency' => 'INR',
            'status' => 'created',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_stripe',
            'amount' => 1000,
            'currency' => 'inr',
            'status' => 'requires_payment_method',
        ], 200),
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDERPAYPAL',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://paypal.com/approve'],
            ],
        ], 200),
        'securegw-stage.paytm.in/*' => Http::response([
            'body' => [
                'txnToken' => 'txn_token',
                'resultInfo' => ['resultStatus' => 'S', 'resultMsg' => 'Success'],
            ],
            'head' => [],
        ], 200),
        'api-preprod.phonepe.com/*/v1/oauth/token' => Http::response([
            'access_token' => 'ppe_token',
            'expires_in' => 3600,
        ], 200),
        'api-preprod.phonepe.com/*/checkout/v2/pay' => Http::response([
            'orderId' => 'ppe_order',
            'redirectUrl' => 'https://phonepe.test/pay',
        ], 200),
        'api-preprod.phonepe.com/*/pg/v1/pay' => Http::response([
            'data' => [
                'merchantTransactionId' => 'ppe_order',
                'instrumentResponse' => [
                    'redirectInfo' => ['url' => 'https://phonepe.test/pay'],
                ],
            ],
            'success' => true,
            'code' => 'PAYMENT_INITIATED',
        ], 200),
    ]);

    $gateways = ['razorpay', 'stripe', 'paypal', 'paytm', 'phonepe'];
    $requiredMethods = [
        'isSuccess',
        'getData',
        'getRawResponse',
        'getGatewayTransactionId',
        'getMessage',
        'isRedirect',
        'getRedirectUrl',
    ];

    foreach ($gateways as $gateway) {
        $response = Payzy::using($gateway)->charge([
            'amount' => 1000,
            'currency' => 'INR',
            'order_id' => 'ORD-'.$gateway,
            'callback_url' => 'https://example.com/callback',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
            'success_url' => 'https://example.com/success',
        ]);

        expect($response)->toBeInstanceOf(PaymentResponse::class);

        foreach ($requiredMethods as $method) {
            expect(method_exists($response, $method))->toBeTrue("Missing {$method} on {$gateway}");
            $response->{$method}();
        }
    }
});

it('masks secrets before logging', function (): void {
    $masker = new SecretMasker(['secret', 'key', 'authorization']);

    $masked = $masker->mask([
        'secret' => 'super-secret',
        'nested' => ['api_key' => 'abc123'],
        'authorization' => 'Bearer tok',
        'safe' => 'ok',
    ]);

    expect($masked['secret'])->toBe('********')
        ->and($masked['nested']['api_key'])->toBe('********')
        ->and($masked['authorization'])->toBe('********')
        ->and($masked['safe'])->toBe('ok');
});

it('resolves the payment facade from the container', function (): void {
    expect(Payzy::getFacadeRoot())->toBeInstanceOf(PayzyManager::class);
});
