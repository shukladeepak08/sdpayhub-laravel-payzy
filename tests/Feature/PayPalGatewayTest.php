<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Facades\Payzy;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('creates and captures paypal orders', function (): void {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPALORDER',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER'],
            ],
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPALORDER/capture' => Http::response([
            'id' => 'PAYPALORDER',
            'status' => 'COMPLETED',
            'purchase_units' => [
                [
                    'payments' => [
                        'captures' => [
                            ['id' => 'CAPTURE1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']],
                        ],
                    ],
                ],
            ],
        ], 200),
        'api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1/refund' => Http::response([
            'id' => 'REFUND1',
            'status' => 'COMPLETED',
        ], 200),
    ]);

    $create = Payzy::using('paypal')->charge([
        'amount' => 1000,
        'currency' => 'USD',
        'return_url' => 'https://example.com/return',
        'cancel_url' => 'https://example.com/cancel',
    ]);

    $capture = Payzy::using('paypal')->capture(['payment_id' => 'PAYPALORDER']);
    $refund = Payzy::using('paypal')->refund([
        'capture_id' => 'CAPTURE1',
        'amount' => 500,
        'currency' => 'USD',
    ]);

    expect($create->isRedirect())->toBeTrue()
        ->and($create->getGatewayTransactionId())->toBe('PAYPALORDER')
        ->and($capture->isSuccess())->toBeTrue()
        ->and($refund->isSuccess())->toBeTrue();
});

it('verifies paypal webhooks via the verify-webhook-signature API', function (): void {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'token',
            'expires_in' => 3600,
        ], 200),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::sequence()
            ->push(['verification_status' => 'SUCCESS'], 200)
            ->push(['verification_status' => 'FAILURE'], 200),
    ]);

    $payload = [
        'id' => 'WH-EVENT-1',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'CAPTURE1'],
    ];

    $headers = [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert',
        'PAYPAL-TRANSMISSION-ID' => 'tx-1',
        'PAYPAL-TRANSMISSION-SIG' => 'sig',
        'PAYPAL-TRANSMISSION-TIME' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $ok = Payzy::using('paypal')->verifyWebhook([
        'payload' => $payload,
        'raw_body' => json_encode($payload),
        'headers' => $headers,
    ]);

    expect($ok->isSuccess())->toBeTrue();

    $headers['PAYPAL-TRANSMISSION-ID'] = 'tx-2';

    expect(fn () => Payzy::using('paypal')->verifyWebhook([
        'payload' => $payload,
        'headers' => $headers,
    ]))->toThrow(WebhookVerificationException::class);
});
