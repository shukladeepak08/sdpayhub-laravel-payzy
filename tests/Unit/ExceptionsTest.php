<?php

declare(strict_types=1);

use Sdpayhub\Payzy\Exceptions\ConfigurationException;
use Sdpayhub\Payzy\Exceptions\GatewayTimeoutException;
use Sdpayhub\Payzy\Exceptions\IdempotencyConflictException;
use Sdpayhub\Payzy\Exceptions\InvalidGatewayException;
use Sdpayhub\Payzy\Exceptions\PaymentFailedException;
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Exceptions\RefundFailedException;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;

it('ensures every exception extends PayzyException', function (string $class): void {
    $exception = new $class('test');

    expect($exception)->toBeInstanceOf(PayzyException::class)
        ->and($exception->getMessage())->toBe('test');
})->with([
    InvalidGatewayException::class,
    PaymentFailedException::class,
    RefundFailedException::class,
    ConfigurationException::class,
    WebhookVerificationException::class,
    IdempotencyConflictException::class,
    GatewayTimeoutException::class,
]);

it('exposes context on the base exception', function (): void {
    $exception = new PayzyException('boom', 0, null, ['gateway' => 'stripe']);

    expect($exception->getContext())->toBe(['gateway' => 'stripe']);
});
