<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sdpayhub\Payzy\Facades\Payzy;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('initiates paytm transactions and refunds', function (): void {
    Http::fake([
        'securegw-stage.paytm.in/*' => Http::sequence()
            ->push([
                'body' => [
                    'txnToken' => 'TOKEN123',
                    'resultInfo' => ['resultStatus' => 'S', 'resultMsg' => 'Success'],
                ],
            ], 200)
            ->push([
                'body' => [
                    'resultInfo' => ['resultStatus' => 'TXN_SUCCESS', 'resultMsg' => 'Success'],
                    'txnId' => 'TXN1',
                    'orderId' => 'ORD1',
                    'txnAmount' => '10.00',
                ],
            ], 200)
            ->push([
                'body' => [
                    'resultInfo' => ['resultStatus' => 'PENDING', 'resultMsg' => 'Accepted'],
                    'refundId' => 'RFND1',
                ],
            ], 200),
    ]);

    $create = Payzy::using('paytm')->charge([
        'amount' => 1000,
        'currency' => 'INR',
        'order_id' => 'ORD1',
        'callback_url' => 'https://example.com/callback',
    ]);

    $status = Payzy::using('paytm')->status([
        'order_id' => 'ORD1',
        'payment_id' => 'TXN1',
    ]);

    $refund = Payzy::using('paytm')->partialRefund([
        'order_id' => 'ORD1',
        'payment_id' => 'TXN1',
        'amount' => 500,
    ]);

    expect($create->isSuccess())->toBeTrue()
        ->and($status->isSuccess())->toBeTrue()
        ->and($refund->isSuccess())->toBeTrue();
});

it('initiates phonepe payments with oauth', function (): void {
    Http::fake([
        'api-preprod.phonepe.com/*/v1/oauth/token' => Http::response([
            'access_token' => 'ppe_token',
            'expires_in' => 3600,
        ], 200),
        'api-preprod.phonepe.com/*' => Http::response([
            'orderId' => 'PPE1',
            'state' => 'PENDING',
            'redirectUrl' => 'https://phonepe.test/pay',
            'data' => [
                'merchantTransactionId' => 'PPE1',
                'instrumentResponse' => [
                    'redirectInfo' => ['url' => 'https://phonepe.test/pay'],
                ],
            ],
            'success' => true,
            'code' => 'PAYMENT_INITIATED',
        ], 200),
    ]);

    $create = Payzy::using('phonepe')->charge([
        'amount' => 1000,
        'currency' => 'INR',
        'order_id' => 'PPE1',
        'callback_url' => 'https://example.com/callback',
        'redirect_url' => 'https://example.com/redirect',
    ]);

    expect($create->isSuccess())->toBeTrue();
});
