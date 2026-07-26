<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways\Paytm;

use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Gateways\AbstractGateway;
use Sdpayhub\Payzy\Support\Signature;

/**
 * Complete Paytm All-in-One / Payment Gateway implementation.
 *
 * Checksums use a package-level HMAC-SHA256 over a sorted key=value& query string
 * (not the official Paytm AES-with-salt SDK checksum). Requests and callbacks are
 * verified with the same method for consistency within this package.
 */
final class PaytmGateway extends AbstractGateway
{
    public function getName(): string
    {
        return 'paytm';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfigKeys(): array
    {
        return ['merchant_id', 'merchant_key'];
    }

    /**
     * Initiate a Paytm transaction and return a payment-page redirect or txnToken.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id');

        $mid = $this->configString('merchant_id');
        $orderId = (string) $payload['order_id'];
        $amountMinor = (int) $payload['amount'];
        $currency = strtoupper((string) ($payload['currency'] ?? 'INR'));
        $amountMajor = number_format($amountMinor / 100, 2, '.', '');

        $body = [
            'requestType' => 'Payment',
            'mid' => $mid,
            'websiteName' => (string) ($this->config['website'] ?? 'WEBSTAGING'),
            'orderId' => $orderId,
            'txnAmount' => [
                'value' => $amountMajor,
                'currency' => $currency,
            ],
            'userInfo' => [
                'custId' => (string) ($payload['customer_id']
                    ?? $payload['customer']['id']
                    ?? $payload['customer']['custId']
                    ?? 'CUST_'.$orderId),
            ],
            'callbackUrl' => (string) ($payload['callback_url'] ?? $payload['callbackUrl'] ?? ''),
        ];

        if ($body['callbackUrl'] === '') {
            unset($body['callbackUrl']);
        }

        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $body['userInfo'] = array_filter([
                'custId' => $body['userInfo']['custId'],
                'email' => $payload['customer']['email'] ?? null,
                'firstName' => $payload['customer']['name'] ?? $payload['customer']['firstName'] ?? null,
                'mobile' => $payload['customer']['phone'] ?? $payload['customer']['mobile'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
        }

        if (isset($payload['channel_id']) || isset($this->config['channel_id'])) {
            $body['channelId'] = (string) ($payload['channel_id'] ?? $this->config['channel_id']);
        }

        if (isset($this->config['industry_type'])) {
            $body['industryTypeId'] = (string) $this->config['industry_type'];
        }

        $signature = $this->generateChecksum($body);
        $request = [
            'head' => [
                'signature' => $signature,
            ],
            'body' => $body,
        ];

        $url = sprintf(
            '%s/theia/api/v1/initiateTransaction?mid=%s&orderId=%s',
            $this->baseUrl(),
            rawurlencode($mid),
            rawurlencode($orderId)
        );

        $response = $this->api('POST', $url, $request, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->extractErrorMessage($response['body'], 'Paytm transaction initiation failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = is_array($response['body']['body'] ?? null) ? $response['body']['body'] : $response['body'];
        $resultInfo = is_array($responseBody['resultInfo'] ?? null) ? $responseBody['resultInfo'] : [];
        $resultStatus = (string) ($resultInfo['resultStatus'] ?? '');

        if ($resultStatus !== '' && ! in_array(strtoupper($resultStatus), ['S', 'SUCCESS', 'TXN_SUCCESS'], true)) {
            return $this->mapFailure(
                message: (string) ($resultInfo['resultMsg'] ?? 'Paytm transaction initiation failed.'),
                body: $responseBody,
                raw: $response,
                transactionId: $orderId,
                status: $resultStatus,
            );
        }

        $txnToken = isset($responseBody['txnToken']) ? (string) $responseBody['txnToken'] : null;
        $data = array_merge($responseBody, [
            'order_id' => $orderId,
            'txnToken' => $txnToken,
            'mid' => $mid,
        ]);

        $redirectUrl = sprintf(
            '%s/theia/api/v1/showPaymentPage?mid=%s&orderId=%s',
            $this->baseUrl(),
            rawurlencode($mid),
            rawurlencode($orderId)
        );

        if ($txnToken !== null && $txnToken !== '') {
            return $this->mapRedirect(
                url: $redirectUrl,
                body: $data,
                raw: $response,
                transactionId: $orderId,
                amount: $amountMinor,
                currency: $currency,
            );
        }

        return $this->mapSuccess(
            body: $data,
            raw: $response,
            transactionId: $orderId,
            message: 'Paytm transaction initiated.',
            status: 'pending',
            amount: $amountMinor,
            currency: $currency,
        );
    }

    /**
     * Paytm auto-captures authorised payments; confirm via status when an order id is provided.
     *
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['payment_id'] ?? '');

        if ($orderId !== '') {
            $status = $this->status(['order_id' => $orderId]);

            if ($status->isSuccess()) {
                return $this->mapSuccess(
                    body: array_merge($status->getData(), ['auto_captured' => true]),
                    raw: $status->getRawResponse(),
                    transactionId: $status->getGatewayTransactionId() ?? $orderId,
                    message: 'Paytm payments are auto-captured; current status retrieved.',
                    status: $status->getStatus() ?? 'captured',
                    amount: $status->getAmount(),
                    currency: $status->getCurrency(),
                );
            }

            return $status;
        }

        return $this->mapSuccess(
            body: ['auto_captured' => true],
            raw: [],
            transactionId: null,
            message: 'Paytm auto-captures payments; no separate capture call is required.',
            status: 'captured',
            amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : 'INR',
        );
    }

    /**
     * Apply a full (or amount-specified) Paytm refund.
     *
     * @param  array<string, mixed>  $payload
     */
    public function refund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'order_id', 'payment_id', 'amount');

        $mid = $this->configString('merchant_id');
        $orderId = (string) $payload['order_id'];
        $txnId = (string) $payload['payment_id'];
        $amountMinor = (int) $payload['amount'];
        $amountMajor = number_format($amountMinor / 100, 2, '.', '');
        $refId = (string) ($payload['refund_id'] ?? $payload['ref_id'] ?? ('REF_'.$orderId.'_'.time()));

        $body = [
            'mid' => $mid,
            'txnType' => 'REFUND',
            'orderId' => $orderId,
            'txnId' => $txnId,
            'refId' => $refId,
            'refundAmount' => $amountMajor,
        ];

        $request = [
            'head' => [
                'signature' => $this->generateChecksum($body),
            ],
            'body' => $body,
        ];

        $response = $this->api(
            'POST',
            $this->baseUrl().'/refund/apply/',
            $request,
            $payload['idempotency_key'] ?? null
        );

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->extractErrorMessage($response['body'], 'Paytm refund failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $txnId,
                status: 'refund_failed',
            );
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = is_array($response['body']['body'] ?? null) ? $response['body']['body'] : $response['body'];
        $resultInfo = is_array($responseBody['resultInfo'] ?? null) ? $responseBody['resultInfo'] : [];
        $resultStatus = strtoupper((string) ($resultInfo['resultStatus'] ?? ''));

        if ($resultStatus !== '' && ! in_array($resultStatus, ['S', 'SUCCESS', 'PENDING', 'TXN_SUCCESS'], true)) {
            return $this->mapFailure(
                message: (string) ($resultInfo['resultMsg'] ?? 'Paytm refund failed.'),
                body: $responseBody,
                raw: $response,
                transactionId: $txnId,
                status: 'refund_failed',
            );
        }

        return $this->mapSuccess(
            body: $responseBody,
            raw: $response,
            transactionId: isset($responseBody['refundId']) ? (string) $responseBody['refundId'] : $refId,
            message: 'Paytm refund submitted.',
            status: $resultStatus !== '' ? strtolower($resultStatus) : 'processed',
            amount: $amountMinor,
            currency: (string) ($payload['currency'] ?? 'INR'),
        );
    }

    /**
     * Apply a partial Paytm refund for the given amount.
     *
     * @param  array<string, mixed>  $payload
     */
    public function partialRefund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'order_id', 'payment_id', 'amount');

        return $this->refund($payload);
    }

    /**
     * Fetch Paytm order / transaction status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function status(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['payment_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['order_id' => $orderId], 'order_id');

        $mid = $this->configString('merchant_id');
        $body = [
            'mid' => $mid,
            'orderId' => $orderId,
        ];

        $request = [
            'head' => [
                'signature' => $this->generateChecksum($body),
            ],
            'body' => $body,
        ];

        $response = $this->api('POST', $this->baseUrl().'/v3/order/status', $request);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->extractErrorMessage($response['body'], 'Unable to fetch Paytm order status.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = is_array($response['body']['body'] ?? null) ? $response['body']['body'] : $response['body'];
        $resultInfo = is_array($responseBody['resultInfo'] ?? null) ? $responseBody['resultInfo'] : [];
        $txnStatus = (string) ($responseBody['resultStatus'] ?? $resultInfo['resultStatus'] ?? $responseBody['txnStatus'] ?? 'unknown');
        $txnId = isset($responseBody['txnId']) ? (string) $responseBody['txnId'] : $orderId;
        $amountMajor = $responseBody['txnAmount'] ?? null;
        $amountMinor = is_numeric($amountMajor) ? (int) round(((float) $amountMajor) * 100) : null;

        return $this->mapSuccess(
            body: $responseBody,
            raw: $response,
            transactionId: $txnId,
            message: 'Paytm order status retrieved.',
            status: $txnStatus,
            amount: $amountMinor,
            currency: (string) ($responseBody['currency'] ?? $payload['currency'] ?? 'INR'),
        );
    }

    /**
     * Cancel an unpaid Paytm order after confirming it is not yet successful.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancel(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['payment_id'] ?? '');
        $this->requireKeys(['order_id' => $orderId], 'order_id');

        $statusResponse = $this->status(['order_id' => $orderId]);

        if (! $statusResponse->isSuccess()) {
            return $this->mapFailure(
                message: $statusResponse->getMessage() ?? 'Unable to cancel Paytm order.',
                body: $statusResponse->getData(),
                raw: $statusResponse->getRawResponse(),
                transactionId: $orderId,
            );
        }

        $data = $statusResponse->getData();
        $resultInfo = is_array($data['resultInfo'] ?? null) ? $data['resultInfo'] : [];
        $txnStatus = strtoupper((string) (
            $data['txnStatus']
            ?? $data['resultStatus']
            ?? $resultInfo['resultStatus']
            ?? $statusResponse->getStatus()
            ?? ''
        ));

        if (in_array($txnStatus, ['TXN_SUCCESS', 'SUCCESS', 'S', 'CAPTURED', 'AUTHORIZED'], true)) {
            return $this->mapFailure(
                message: 'Only unpaid Paytm orders can be cancelled.',
                body: $data,
                raw: $statusResponse->getRawResponse(),
                transactionId: $orderId,
                status: $txnStatus,
            );
        }

        return $this->mapSuccess(
            body: array_merge($data, ['cancelled' => true]),
            raw: $statusResponse->getRawResponse(),
            transactionId: $orderId,
            message: 'Paytm order marked as cancelled (unpaid / not successful).',
            status: 'cancelled',
            amount: $statusResponse->getAmount(),
            currency: $statusResponse->getCurrency(),
        );
    }

    /**
     * Verify callback signature then optionally refresh status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): PaymentResponse
    {
        $signatureCheck = $this->verifySignature($payload);

        if (! $signatureCheck->isSuccess()) {
            return $signatureCheck;
        }

        $orderId = (string) ($payload['order_id'] ?? $payload['ORDERID'] ?? '');

        if ($orderId === '') {
            return $signatureCheck;
        }

        return $this->status(['order_id' => $orderId]);
    }

    /**
     * Verify Paytm callback / client checksum using the package HMAC checksum.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): PaymentResponse
    {
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : $payload;
        $checksum = (string) (
            $payload['checksum']
            ?? $payload['signature']
            ?? $params['CHECKSUMHASH']
            ?? $params['signature']
            ?? ''
        );

        if ($checksum === '') {
            return $this->mapFailure(
                message: 'Paytm checksum is missing.',
                body: $params,
                status: 'signature_invalid',
            );
        }

        $verifyParams = $params;
        unset($verifyParams['CHECKSUMHASH'], $verifyParams['checksum'], $verifyParams['signature'], $verifyParams['params']);

        $expected = $this->generateChecksum($verifyParams);

        if (! Signature::equals($expected, $checksum)) {
            return $this->mapFailure(
                message: 'Invalid Paytm checksum.',
                body: [
                    'order_id' => $params['ORDERID'] ?? $params['order_id'] ?? null,
                    'txn_id' => $params['TXNID'] ?? $params['txn_id'] ?? null,
                ],
                status: 'signature_invalid',
            );
        }

        $orderId = (string) ($params['ORDERID'] ?? $params['order_id'] ?? '');
        $txnId = (string) ($params['TXNID'] ?? $params['txn_id'] ?? $params['payment_id'] ?? $orderId);

        return $this->mapSuccess(
            body: [
                'order_id' => $orderId,
                'payment_id' => $txnId,
                'signature_valid' => true,
                'params' => $verifyParams,
            ],
            raw: [],
            transactionId: $txnId !== '' ? $txnId : null,
            message: 'Paytm checksum verified.',
            status: 'verified',
        );
    }

    /**
     * Verify an inbound Paytm webhook / callback checksum.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookVerificationException
     */
    public function verifyWebhook(array $payload): PaymentResponse
    {
        /** @var array<string, mixed> $body */
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $checksum = (string) (
            $payload['signature']
            ?? $body['CHECKSUMHASH']
            ?? $body['checksum']
            ?? $body['signature']
            ?? ''
        );

        if ($checksum === '') {
            throw new WebhookVerificationException(
                message: 'Paytm webhook checksum is missing.',
                context: ['gateway' => $this->getName()],
            );
        }

        $verifyParams = $body;
        unset($verifyParams['CHECKSUMHASH'], $verifyParams['checksum'], $verifyParams['signature']);

        $expected = $this->generateChecksum($verifyParams);

        if (! Signature::equals($expected, $checksum)) {
            throw new WebhookVerificationException(
                message: 'Invalid Paytm webhook checksum.',
                context: ['gateway' => $this->getName()],
            );
        }

        $orderId = (string) ($body['ORDERID'] ?? $body['orderId'] ?? $body['order_id'] ?? '');
        $txnId = (string) ($body['TXNID'] ?? $body['txnId'] ?? $body['txn_id'] ?? '');
        $eventId = $orderId.$txnId;

        if ($eventId === '') {
            $raw = (string) ($payload['raw_body'] ?? json_encode($body));
            $eventId = hash('sha256', $raw);
        }

        $eventType = (string) (
            $body['STATUS']
            ?? $body['status']
            ?? $body['txnStatus']
            ?? 'paytm.callback'
        );

        return $this->mapSuccess(
            body: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'timestamp' => isset($body['TXNDATE']) ? strtotime((string) $body['TXNDATE']) ?: null : null,
                'payload' => $body,
                'signature_valid' => true,
            ],
            raw: $body,
            transactionId: $txnId !== '' ? $txnId : ($orderId !== '' ? $orderId : null),
            message: 'Paytm webhook verified.',
            status: 'webhook_verified',
        );
    }

    /**
     * Create a Paytm payment by initiating a transaction with a callback URL (redirect flow).
     *
     * @param  array<string, mixed>  $payload
     */
    public function paymentLink(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id', 'callback_url');

        return $this->create($payload);
    }

    /**
     * Attempt a UPI / QR-oriented Paytm initiate, or return an unsupported failure response.
     *
     * @param  array<string, mixed>  $payload
     */
    public function qr(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id');

        $channel = (string) ($payload['channel_id'] ?? $payload['channel'] ?? 'UPI');

        if (! in_array(strtoupper($channel), ['UPI', 'WAP', 'WEB', 'QR'], true)) {
            return $this->mapFailure(
                message: 'Paytm QR is not supported for the requested channel.',
                body: $payload,
                status: 'unsupported',
            );
        }

        $createPayload = array_merge($payload, [
            'channel_id' => $channel === 'QR' ? 'UPI' : $channel,
        ]);

        $response = $this->create($createPayload);

        if (! $response->isSuccess() && ! $response->isRedirect()) {
            return $this->mapFailure(
                message: $response->getMessage() ?? 'Paytm UPI QR initiation failed.',
                body: $response->getData(),
                raw: $response->getRawResponse(),
                transactionId: $response->getGatewayTransactionId(),
                status: 'unsupported',
            );
        }

        return $this->mapSuccess(
            body: array_merge($response->getData(), [
                'qr' => true,
                'channel' => $channel,
                'redirect_url' => $response->getRedirectUrl(),
            ]),
            raw: $response->getRawResponse(),
            transactionId: $response->getGatewayTransactionId(),
            message: 'Paytm UPI QR / intent transaction initiated.',
            status: $response->getStatus() ?? 'pending',
            amount: $response->getAmount() ?? (int) $payload['amount'],
            currency: $response->getCurrency() ?? (string) ($payload['currency'] ?? 'INR'),
        );
    }

    /**
     * Package checksum: HMAC-SHA256 over a sorted key=value& query string.
     *
     * Nested arrays are JSON-encoded. CHECKSUMHASH / signature keys are ignored.
     * This is the package checksum algorithm — verify callbacks with the same method.
     *
     * @param  array<string, mixed>  $params
     */
    private function generateChecksum(array $params): string
    {
        unset($params['CHECKSUMHASH'], $params['checksum'], $params['signature']);

        ksort($params);

        $parts = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $value = $encoded === false ? '' : $encoded;
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            $parts[] = $key.'='.$value;
        }

        $str = implode('&', $parts);

        return Signature::hmacSha256($str, $this->configString('merchant_key'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>}
     */
    private function api(string $method, string $url, array $body = [], mixed $idempotencyKey = null): array
    {
        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: $method,
            url: $url,
            headers: [
                'Content-Type' => 'application/json',
            ],
            json: in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) ? $body : null,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractErrorMessage(array $body, string $fallback): string
    {
        $nested = is_array($body['body'] ?? null) ? $body['body'] : $body;
        $resultInfo = is_array($nested['resultInfo'] ?? null) ? $nested['resultInfo'] : [];

        $message = $resultInfo['resultMsg']
            ?? $nested['errorMessage']
            ?? $nested['message']
            ?? $body['errorMessage']
            ?? $body['message']
            ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
