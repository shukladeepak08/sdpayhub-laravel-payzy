<?php

declare(strict_types=1);

use Sdpayhub\Payzy\DTOs\PaymentResponse;

it('exposes the mandated response contract', function (): void {
    $response = PaymentResponse::success(
        data: ['id' => 'pay_1'],
        rawResponse: ['raw' => true],
        gatewayTransactionId: 'pay_1',
        message: 'ok',
        status: 'captured',
        amount: 1000,
        currency: 'INR',
        meta: ['gateway' => 'razorpay'],
    );

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getData())->toBe(['id' => 'pay_1'])
        ->and($response->getRawResponse())->toBe(['raw' => true])
        ->and($response->getGatewayTransactionId())->toBe('pay_1')
        ->and($response->getMessage())->toBe('ok')
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getRedirectUrl())->toBeNull()
        ->and($response->getStatus())->toBe('captured')
        ->and($response->getAmount())->toBe(1000)
        ->and($response->getCurrency())->toBe('INR');
});

it('builds redirect and failure responses', function (): void {
    $redirect = PaymentResponse::redirect('https://pay.example/redirect', ['a' => 1]);
    $failure = PaymentResponse::failure('Nope');

    expect($redirect->isSuccess())->toBeTrue()
        ->and($redirect->isRedirect())->toBeTrue()
        ->and($redirect->getRedirectUrl())->toBe('https://pay.example/redirect')
        ->and($failure->isSuccess())->toBeFalse()
        ->and($failure->getMessage())->toBe('Nope');
});
