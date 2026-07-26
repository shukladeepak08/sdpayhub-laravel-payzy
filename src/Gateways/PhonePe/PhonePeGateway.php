<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways\PhonePe;

use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\ConfigurationException;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Gateways\AbstractGateway;
use Sdpayhub\Payzy\Support\Signature;

/**
 * Complete PhonePe Payment Gateway (Standard Checkout / OAuth) implementation.
 *
 * Uses OAuth client-credentials tokens for Checkout v2 APIs, with optional legacy
 * X-VERIFY (salt_key + salt_index) verification for webhooks and signatures.
 */
final class PhonePeGateway extends AbstractGateway
{
    private ?string $accessToken = null;

    private ?int $accessTokenExpiresAt = null;

    public function getName(): string
    {
        return 'phonepe';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfigKeys(): array
    {
        return ['client_id', 'client_secret', 'merchant_id'];
    }

    /**
     * Create a PhonePe payment (Checkout v2 pay page / redirect).
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id');

        $merchantOrderId = (string) $payload['order_id'];
        $amount = (int) $payload['amount'];
        $currency = strtoupper((string) ($payload['currency'] ?? 'INR'));
        $redirectUrl = (string) ($payload['callback_url'] ?? $payload['redirect_url'] ?? $payload['redirectUrl'] ?? '');

        $paymentFlow = is_array($payload['payment_flow'] ?? null)
            ? $payload['payment_flow']
            : [
                'type' => 'PG_CHECKOUT',
                'merchantUrls' => array_filter([
                    'redirectUrl' => $redirectUrl !== '' ? $redirectUrl : null,
                ]),
            ];

        if ($redirectUrl !== '' && ! isset($paymentFlow['merchantUrls'])) {
            $paymentFlow['merchantUrls'] = ['redirectUrl' => $redirectUrl];
        }

        $body = array_filter([
            'merchantOrderId' => $merchantOrderId,
            'amount' => $amount,
            'expireAfter' => isset($payload['expire_after']) ? (int) $payload['expire_after'] : null,
            'metaInfo' => is_array($payload['meta'] ?? null) ? $payload['meta'] : (is_array($payload['metaInfo'] ?? null) ? $payload['metaInfo'] : null),
            'paymentFlow' => $paymentFlow,
        ], static fn (mixed $v): bool => $v !== null);

        $response = $this->api(
            'POST',
            $this->payPath(),
            $body,
            $payload['idempotency_key'] ?? null,
            true
        );

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->extractErrorMessage($response['body'], 'PhonePe payment creation failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $merchantOrderId,
            );
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = $response['body'];
        $data = is_array($responseBody['data'] ?? null) ? $responseBody['data'] : $responseBody;
        $redirect = $this->extractRedirectUrl($data, $responseBody);

        if ($redirect !== null) {
            return $this->mapRedirect(
                url: $redirect,
                body: array_merge($data, [
                    'order_id' => $merchantOrderId,
                    'merchantOrderId' => $merchantOrderId,
                ]),
                raw: $response,
                transactionId: isset($data['orderId']) ? (string) $data['orderId'] : $merchantOrderId,
                amount: $amount,
                currency: $currency,
            );
        }

        return $this->mapSuccess(
            body: array_merge($data, [
                'order_id' => $merchantOrderId,
                'merchantOrderId' => $merchantOrderId,
            ]),
            raw: $response,
            transactionId: isset($data['orderId']) ? (string) $data['orderId'] : $merchantOrderId,
            message: 'PhonePe payment created.',
            status: (string) ($data['state'] ?? $data['status'] ?? 'pending'),
            amount: $amount,
            currency: $currency,
        );
    }

    /**
     * PhonePe auto-captures; confirm via order status when an order id is provided.
     *
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['payment_id'] ?? $payload['merchantOrderId'] ?? '');

        if ($orderId !== '') {
            $status = $this->status(['order_id' => $orderId]);

            if ($status->isSuccess()) {
                return $this->mapSuccess(
                    body: array_merge($status->getData(), ['auto_captured' => true]),
                    raw: $status->getRawResponse(),
                    transactionId: $status->getGatewayTransactionId() ?? $orderId,
                    message: 'PhonePe payments are auto-captured; current status retrieved.',
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
            message: 'PhonePe auto-captures payments; no separate capture call is required.',
            status: 'captured',
            amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : 'INR',
        );
    }

    /**
     * Refund a PhonePe payment (full or amount-specified).
     *
     * @param  array<string, mixed>  $payload
     */
    public function refund(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? $payload['originalMerchantOrderId'] ?? '');
        $this->requireKeys([
            'order_id' => $orderId,
            'amount' => $payload['amount'] ?? null,
        ], 'order_id', 'amount');

        $amount = (int) $payload['amount'];
        $merchantRefundId = (string) ($payload['refund_id'] ?? $payload['merchantRefundId'] ?? ('REF_'.$orderId.'_'.time()));

        $body = [
            'merchantRefundId' => $merchantRefundId,
            'originalMerchantOrderId' => $orderId,
            'amount' => $amount,
        ];

        $response = $this->api(
            'POST',
            $this->baseUrl().'/payments/v2/refund',
            $body,
            $payload['idempotency_key'] ?? null,
            true
        );

        if (! ($response['successful'] ?? false)) {
            // Fallback to legacy refund path when v2 is unavailable.
            $legacy = $this->legacyRefund($payload, $orderId, $amount, $merchantRefundId);

            if ($legacy !== null) {
                return $legacy;
            }

            return $this->mapFailure(
                message: $this->extractErrorMessage($response['body'], 'PhonePe refund failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
                status: 'refund_failed',
            );
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = $response['body'];
        $data = is_array($responseBody['data'] ?? null) ? $responseBody['data'] : $responseBody;

        return $this->mapSuccess(
            body: $data,
            raw: $response,
            transactionId: isset($data['refundId']) ? (string) $data['refundId'] : $merchantRefundId,
            message: 'PhonePe refund submitted.',
            status: (string) ($data['state'] ?? $data['status'] ?? 'processed'),
            amount: $amount,
            currency: (string) ($payload['currency'] ?? 'INR'),
        );
    }

    /**
     * Partially refund a PhonePe payment for the given amount.
     *
     * @param  array<string, mixed>  $payload
     */
    public function partialRefund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount');

        return $this->refund($payload);
    }

    /**
     * Fetch PhonePe order status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function status(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? $payload['payment_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['order_id' => $orderId], 'order_id');

        $url = $this->baseUrl().'/checkout/v2/order/'.rawurlencode($orderId).'/status';
        $response = $this->api('GET', $url, [], null, true);

        if (! ($response['successful'] ?? false)) {
            $legacyUrl = $this->baseUrl().'/pg/v1/status/'.$this->configString('merchant_id').'/'.rawurlencode($orderId);
            $legacyResponse = $this->api('GET', $legacyUrl, [], null, true, true);

            if (! ($legacyResponse['successful'] ?? false)) {
                return $this->mapFailure(
                    message: $this->extractErrorMessage($response['body'], 'Unable to fetch PhonePe order status.'),
                    body: $response['body'],
                    raw: $response,
                    transactionId: $orderId,
                );
            }

            $response = $legacyResponse;
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = $response['body'];
        $data = is_array($responseBody['data'] ?? null) ? $responseBody['data'] : $responseBody;
        $amount = isset($data['amount']) ? (int) $data['amount'] : (isset($payload['amount']) ? (int) $payload['amount'] : null);

        return $this->mapSuccess(
            body: $data,
            raw: $response,
            transactionId: isset($data['orderId']) ? (string) $data['orderId'] : $orderId,
            message: 'PhonePe order status retrieved.',
            status: (string) ($data['state'] ?? $data['status'] ?? 'unknown'),
            amount: $amount,
            currency: (string) ($payload['currency'] ?? 'INR'),
        );
    }

    /**
     * Cancel an unpaid PhonePe order when the gateway status allows it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancel(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? $payload['payment_id'] ?? '');
        $this->requireKeys(['order_id' => $orderId], 'order_id');

        $statusResponse = $this->status(['order_id' => $orderId]);

        if (! $statusResponse->isSuccess()) {
            return $this->mapFailure(
                message: $statusResponse->getMessage() ?? 'Unable to cancel PhonePe order.',
                body: $statusResponse->getData(),
                raw: $statusResponse->getRawResponse(),
                transactionId: $orderId,
            );
        }

        $data = $statusResponse->getData();
        $state = strtoupper((string) ($data['state'] ?? $data['status'] ?? $statusResponse->getStatus() ?? ''));

        $paidStates = ['COMPLETED', 'SUCCESS', 'PAID', 'CAPTURED', 'PAYMENT_SUCCESS'];
        $cancellable = ['PENDING', 'CREATED', 'FAILED', 'PAYMENT_PENDING', 'PAYMENT_ERROR', ''];

        if (in_array($state, $paidStates, true)) {
            return $this->mapFailure(
                message: 'Only unpaid PhonePe orders can be cancelled.',
                body: $data,
                raw: $statusResponse->getRawResponse(),
                transactionId: $orderId,
                status: $state,
            );
        }

        if (! in_array($state, $cancellable, true) && $state !== '') {
            return $this->mapFailure(
                message: 'PhonePe order cannot be cancelled in its current state.',
                body: $data,
                raw: $statusResponse->getRawResponse(),
                transactionId: $orderId,
                status: $state,
            );
        }

        return $this->mapSuccess(
            body: array_merge($data, ['cancelled' => true]),
            raw: $statusResponse->getRawResponse(),
            transactionId: $orderId,
            message: 'PhonePe order marked as cancelled (unpaid).',
            status: 'cancelled',
            amount: $statusResponse->getAmount(),
            currency: $statusResponse->getCurrency(),
        );
    }

    /**
     * Verify signature (when salt_key is configured) then refresh status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): PaymentResponse
    {
        $signatureCheck = $this->verifySignature($payload);

        if (! $signatureCheck->isSuccess()) {
            return $signatureCheck;
        }

        $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? $payload['transactionId'] ?? '');

        if ($orderId === '') {
            return $signatureCheck;
        }

        return $this->status(['order_id' => $orderId]);
    }

    /**
     * Verify PhonePe X-VERIFY style signature when salt_key is configured.
     *
     * X-VERIFY = SHA256(base64payload + path + salt_key) + ### + saltIndex
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): PaymentResponse
    {
        $saltKey = $this->config['salt_key'] ?? null;

        if (! is_string($saltKey) || $saltKey === '') {
            // OAuth-era flows may not use X-VERIFY; treat as verified when salt is absent.
            $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? '');

            return $this->mapSuccess(
                body: [
                    'order_id' => $orderId,
                    'signature_valid' => true,
                    'verification' => 'skipped_no_salt_key',
                ],
                raw: [],
                transactionId: $orderId !== '' ? $orderId : null,
                message: 'PhonePe signature verification skipped (salt_key not configured).',
                status: 'verified',
            );
        }

        $base64Payload = (string) ($payload['base64'] ?? $payload['base64_payload'] ?? '');
        $path = (string) ($payload['path'] ?? $payload['endpoint'] ?? '');
        $signature = (string) ($payload['signature'] ?? $payload['x_verify'] ?? $payload['X-VERIFY'] ?? '');

        if ($base64Payload === '' && isset($payload['payload'])) {
            $encoded = base64_encode(
                is_string($payload['payload'])
                    ? $payload['payload']
                    : (string) json_encode($payload['payload'], JSON_UNESCAPED_SLASHES)
            );
            $base64Payload = $encoded;
        }

        if ($base64Payload === '' || $path === '' || $signature === '') {
            return $this->mapFailure(
                message: 'PhonePe signature payload, path, or X-VERIFY header is missing.',
                body: [
                    'order_id' => $payload['order_id'] ?? $payload['merchantOrderId'] ?? null,
                ],
                status: 'signature_invalid',
            );
        }

        $saltIndex = (string) ($payload['salt_index'] ?? $this->config['salt_index'] ?? '1');
        $expected = hash('sha256', $base64Payload.$path.$saltKey).'###'.$saltIndex;

        if (! Signature::equals($expected, $signature)) {
            return $this->mapFailure(
                message: 'Invalid PhonePe X-VERIFY signature.',
                body: [
                    'order_id' => $payload['order_id'] ?? $payload['merchantOrderId'] ?? null,
                ],
                status: 'signature_invalid',
            );
        }

        $orderId = (string) ($payload['order_id'] ?? $payload['merchantOrderId'] ?? '');

        return $this->mapSuccess(
            body: [
                'order_id' => $orderId,
                'signature_valid' => true,
            ],
            raw: [],
            transactionId: $orderId !== '' ? $orderId : null,
            message: 'PhonePe signature verified.',
            status: 'verified',
        );
    }

    /**
     * Verify inbound PhonePe webhook Authorization / X-VERIFY header.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookVerificationException
     */
    public function verifyWebhook(array $payload): PaymentResponse
    {
        /** @var array<string, mixed> $body */
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        /** @var array<string, string> $headers */
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        $signature = (string) (
            $payload['signature']
            ?? $this->headerValue($headers, 'X-VERIFY')
            ?? $this->headerValue($headers, 'Authorization')
            ?? ''
        );

        $saltKey = $this->config['salt_key'] ?? null;
        $rawBody = (string) ($payload['raw_body'] ?? '');

        if (is_string($saltKey) && $saltKey !== '') {
            if ($signature === '') {
                throw new WebhookVerificationException(
                    message: 'PhonePe webhook X-VERIFY / Authorization header is missing.',
                    context: ['gateway' => $this->getName()],
                );
            }

            $base64Payload = (string) ($payload['base64'] ?? ($rawBody !== '' ? base64_encode($rawBody) : ''));

            if ($base64Payload === '' && $body !== []) {
                $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
                $base64Payload = base64_encode($encoded === false ? '' : $encoded);
            }

            $path = (string) ($payload['path'] ?? $payload['endpoint'] ?? '/payments/v2/webhook');
            $saltIndex = (string) ($this->config['salt_index'] ?? '1');
            $expected = hash('sha256', $base64Payload.$path.$saltKey).'###'.$saltIndex;

            // Also accept username:password Authorization when PhonePe sends basic auth style credentials.
            $valid = Signature::equals($expected, $signature)
                || Signature::equals($expected, preg_replace('/^Bearer\s+/i', '', $signature) ?? $signature);

            if (! $valid) {
                throw new WebhookVerificationException(
                    message: 'Invalid PhonePe webhook signature.',
                    context: ['gateway' => $this->getName()],
                );
            }
        } elseif ($signature === '' && $rawBody === '' && $body === []) {
            throw new WebhookVerificationException(
                message: 'PhonePe webhook payload is empty.',
                context: ['gateway' => $this->getName()],
            );
        }

        $nested = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        $data = is_array($nested['data'] ?? null) ? $nested['data'] : $nested;

        $orderId = (string) (
            $data['merchantOrderId']
            ?? $data['orderId']
            ?? $body['merchantOrderId']
            ?? $body['orderId']
            ?? $nested['merchantOrderId']
            ?? ''
        );

        $eventId = $orderId !== '' ? $orderId : hash('sha256', $rawBody !== '' ? $rawBody : (string) json_encode($body));
        $eventType = (string) (
            $body['event']
            ?? $data['state']
            ?? $data['status']
            ?? $nested['type']
            ?? 'phonepe.webhook'
        );

        return $this->mapSuccess(
            body: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'timestamp' => isset($data['expireAt']) ? (int) $data['expireAt'] : null,
                'payload' => $body !== [] ? $body : $data,
                'signature_valid' => true,
            ],
            raw: $body,
            transactionId: $orderId !== '' ? $orderId : null,
            message: 'PhonePe webhook verified.',
            status: 'webhook_verified',
        );
    }

    /**
     * Create a PhonePe pay-page payment link (same as create redirect flow).
     *
     * @param  array<string, mixed>  $payload
     */
    public function paymentLink(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id');

        if (! isset($payload['callback_url']) && ! isset($payload['redirect_url'])) {
            $this->requireKeys($payload, 'callback_url');
        }

        return $this->create($payload);
    }

    /**
     * Attempt a UPI intent / QR payment when supported; otherwise return failure.
     *
     * @param  array<string, mixed>  $payload
     */
    public function qr(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'order_id');

        $redirectUrl = (string) ($payload['callback_url'] ?? $payload['redirect_url'] ?? '');

        $createPayload = array_merge($payload, [
            'payment_flow' => [
                'type' => 'PG_CHECKOUT',
                'paymentModeConfig' => [
                    'enabledPaymentModes' => [
                        ['type' => 'UPI_INTENT'],
                        ['type' => 'UPI_QR'],
                    ],
                ],
                'merchantUrls' => array_filter([
                    'redirectUrl' => $redirectUrl !== '' ? $redirectUrl : null,
                ]),
            ],
        ]);

        $response = $this->create($createPayload);

        if ($response->isRedirect() || $response->isSuccess()) {
            $intentUrl = $response->getRedirectUrl();
            $data = $response->getData();
            $qrData = $data['qrData'] ?? $data['intentUrl'] ?? $intentUrl;

            if ($qrData !== null && $qrData !== '') {
                return $this->mapSuccess(
                    body: array_merge($data, [
                        'qr' => true,
                        'qrData' => $qrData,
                        'intentUrl' => $intentUrl,
                    ]),
                    raw: $response->getRawResponse(),
                    transactionId: $response->getGatewayTransactionId(),
                    message: 'PhonePe UPI intent / QR payment initiated.',
                    status: $response->getStatus() ?? 'pending',
                    amount: $response->getAmount() ?? (int) $payload['amount'],
                    currency: $response->getCurrency() ?? (string) ($payload['currency'] ?? 'INR'),
                );
            }
        }

        return $this->mapFailure(
            message: 'PhonePe intent QR is not available for this configuration.',
            body: $response->getData(),
            raw: $response->getRawResponse(),
            transactionId: $response->getGatewayTransactionId(),
            status: 'unsupported',
        );
    }

    /**
     * Obtain and cache an OAuth access token using client credentials.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && $this->accessTokenExpiresAt > (time() + 60)
        ) {
            return $this->accessToken;
        }

        $oauthBase = $this->config['oauth_url'] ?? null;

        if (! is_string($oauthBase) || $oauthBase === '') {
            throw new ConfigurationException(
                message: 'Missing oauth_url for gateway [phonepe].',
                context: ['gateway' => $this->getName()],
            );
        }

        $url = rtrim($oauthBase, '/').'/v1/oauth/token';

        $form = [
            'client_id' => $this->configString('client_id'),
            'client_version' => (string) ($this->config['client_version'] ?? '1'),
            'client_secret' => $this->configString('client_secret'),
            'grant_type' => 'client_credentials',
        ];

        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: 'POST',
            url: $url,
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            form: $form,
        );

        if (! ($response['successful'] ?? false)) {
            throw new ConfigurationException(
                message: $this->extractErrorMessage($response['body'], 'PhonePe OAuth token request failed.'),
                context: ['gateway' => $this->getName(), 'status' => $response['status'] ?? null],
            );
        }

        $token = (string) ($response['body']['access_token'] ?? $response['body']['accessToken'] ?? '');

        if ($token === '') {
            throw new ConfigurationException(
                message: 'PhonePe OAuth response did not include an access token.',
                context: ['gateway' => $this->getName()],
            );
        }

        $expiresAt = $response['body']['expires_at'] ?? $response['body']['expiresAt'] ?? null;
        $expiresIn = $response['body']['expires_in'] ?? $response['body']['expiresIn'] ?? null;

        if (is_numeric($expiresAt)) {
            $this->accessTokenExpiresAt = (int) $expiresAt;
        } elseif (is_numeric($expiresIn)) {
            $this->accessTokenExpiresAt = time() + (int) $expiresIn;
        } else {
            $this->accessTokenExpiresAt = time() + 3000;
        }

        $this->accessToken = $token;

        return $token;
    }

    /**
     * Preferred Checkout v2 path, with legacy /pg/v1/pay as alternate via config.
     */
    private function payPath(): string
    {
        $path = (string) ($this->config['pay_path'] ?? '/checkout/v2/pay');

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $this->baseUrl().$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function legacyRefund(array $payload, string $orderId, int $amount, string $merchantRefundId): ?PaymentResponse
    {
        $saltKey = $this->config['salt_key'] ?? null;

        if (! is_string($saltKey) || $saltKey === '') {
            return null;
        }

        $inner = [
            'merchantId' => $this->configString('merchant_id'),
            'merchantTransactionId' => $merchantRefundId,
            'originalTransactionId' => (string) ($payload['payment_id'] ?? $payload['transactionId'] ?? $orderId),
            'amount' => $amount,
            'callbackUrl' => (string) ($payload['callback_url'] ?? ''),
        ];

        if ($inner['callbackUrl'] === '') {
            unset($inner['callbackUrl']);
        }

        $encoded = base64_encode((string) json_encode($inner, JSON_UNESCAPED_SLASHES));
        $path = '/pg/v1/refund';
        $saltIndex = (string) ($this->config['salt_index'] ?? '1');
        $xVerify = hash('sha256', $encoded.$path.$saltKey).'###'.$saltIndex;

        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: 'POST',
            url: $this->baseUrl().$path,
            headers: [
                'Content-Type' => 'application/json',
                'X-VERIFY' => $xVerify,
            ],
            json: [
                'request' => $encoded,
            ],
            idempotencyKey: is_string($payload['idempotency_key'] ?? null) ? (string) $payload['idempotency_key'] : null,
        );

        if (! ($response['successful'] ?? false)) {
            return null;
        }

        /** @var array<string, mixed> $responseBody */
        $responseBody = $response['body'];
        $data = is_array($responseBody['data'] ?? null) ? $responseBody['data'] : $responseBody;

        return $this->mapSuccess(
            body: $data,
            raw: $response,
            transactionId: $merchantRefundId,
            message: 'PhonePe legacy refund submitted.',
            status: (string) ($data['state'] ?? $responseBody['code'] ?? 'processed'),
            amount: $amount,
            currency: (string) ($payload['currency'] ?? 'INR'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>}
     */
    private function api(
        string $method,
        string $url,
        array $body = [],
        mixed $idempotencyKey = null,
        bool $withOauth = false,
        bool $withLegacyVerify = false,
    ): array {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($withOauth) {
            $headers['Authorization'] = 'O-Bearer '.$this->getAccessToken();
        }

        if ($withLegacyVerify) {
            $saltKey = $this->config['salt_key'] ?? null;

            if (is_string($saltKey) && $saltKey !== '') {
                $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
                $saltIndex = (string) ($this->config['salt_index'] ?? '1');
                $headers['X-VERIFY'] = hash('sha256', $path.$saltKey).'###'.$saltIndex;
                $headers['X-MERCHANT-ID'] = $this->configString('merchant_id');
            }
        }

        $upper = strtoupper($method);

        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: $method,
            url: $url,
            headers: $headers,
            json: in_array($upper, ['POST', 'PUT', 'PATCH'], true) ? $body : null,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $responseBody
     */
    private function extractRedirectUrl(array $data, array $responseBody): ?string
    {
        foreach (['redirectUrl', 'redirect_url', 'instrumentResponse.redirectInfo.url'] as $key) {
            if (str_contains($key, '.')) {
                $parts = explode('.', $key);
                $cursor = $data;

                foreach ($parts as $part) {
                    if (! is_array($cursor) || ! array_key_exists($part, $cursor)) {
                        $cursor = null;
                        break;
                    }
                    $cursor = $cursor[$part];
                }

                if (is_string($cursor) && $cursor !== '') {
                    return $cursor;
                }

                continue;
            }

            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }

            if (isset($responseBody[$key]) && is_string($responseBody[$key]) && $responseBody[$key] !== '') {
                return $responseBody[$key];
            }
        }

        $instrument = is_array($data['instrumentResponse'] ?? null) ? $data['instrumentResponse'] : [];
        $redirectInfo = is_array($instrument['redirectInfo'] ?? null) ? $instrument['redirectInfo'] : [];

        if (isset($redirectInfo['url']) && is_string($redirectInfo['url']) && $redirectInfo['url'] !== '') {
            return $redirectInfo['url'];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0 && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractErrorMessage(array $body, string $fallback): string
    {
        $message = $body['message']
            ?? $body['errorMessage']
            ?? $body['code']
            ?? (is_array($body['data'] ?? null) ? ($body['data']['message'] ?? null) : null)
            ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
