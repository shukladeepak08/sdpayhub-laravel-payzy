<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways\PayPal;

use Sdpayhub\Payzy\Contracts\SupportsSubscriptions;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\PaymentFailedException;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Gateways\AbstractGateway;

/**
 * Complete PayPal REST gateway implementation (Orders v2 + Billing Subscriptions).
 *
 * PayPal has no Stripe-like Customers API; this gateway implements
 * SupportsSubscriptions only. Subscriber identity is embedded in subscription payloads.
 */
final class PayPalGateway extends AbstractGateway implements SupportsSubscriptions
{
    private ?string $accessToken = null;

    public function getName(): string
    {
        return 'paypal';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfigKeys(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function create(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'currency');

        $amount = (int) $payload['amount'];
        $currency = strtoupper((string) $payload['currency']);

        $purchaseUnit = array_filter([
            'amount' => [
                'currency_code' => $currency,
                'value' => $this->formatMajorAmount($amount),
            ],
            'custom_id' => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'invoice_id' => isset($payload['invoice_id']) ? (string) $payload['invoice_id'] : null,
            'description' => isset($payload['description']) ? (string) $payload['description'] : null,
            'reference_id' => isset($payload['reference_id'])
                ? (string) $payload['reference_id']
                : (isset($payload['order_id']) ? (string) $payload['order_id'] : null),
        ], static fn (mixed $v): bool => $v !== null);

        $body = [
            'intent' => (string) ($payload['intent'] ?? 'CAPTURE'),
            'purchase_units' => [$purchaseUnit],
        ];

        $applicationContext = array_filter([
            'return_url' => isset($payload['return_url']) ? (string) $payload['return_url'] : null,
            'cancel_url' => isset($payload['cancel_url']) ? (string) $payload['cancel_url'] : null,
            'brand_name' => isset($payload['brand_name']) ? (string) $payload['brand_name'] : null,
            'user_action' => isset($payload['user_action']) ? (string) $payload['user_action'] : null,
            'shipping_preference' => isset($payload['shipping_preference'])
                ? (string) $payload['shipping_preference']
                : null,
        ], static fn (mixed $v): bool => $v !== null);

        if ($applicationContext !== []) {
            $body['application_context'] = $applicationContext;
        }

        if (isset($payload['payer']) && is_array($payload['payer'])) {
            $body['payer'] = $payload['payer'];
        }

        $response = $this->api('POST', '/v2/checkout/orders', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal order creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];
        $orderId = isset($order['id']) ? (string) $order['id'] : null;
        $approveUrl = $this->findLink($order, 'approve');

        if ($approveUrl !== null) {
            return $this->mapRedirect(
                url: $approveUrl,
                body: $order,
                raw: $response,
                transactionId: $orderId,
                amount: $amount,
                currency: $currency,
            );
        }

        return $this->mapSuccess(
            body: $order,
            raw: $response,
            transactionId: $orderId,
            message: 'PayPal order created.',
            status: isset($order['status']) ? (string) $order['status'] : 'CREATED',
            amount: $amount,
            currency: $currency,
        );
    }

    public function capture(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['payment_id'] ?? $payload['order_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $orderId], 'payment_id');

        $body = [];
        if (isset($payload['final_capture'])) {
            $body['final_capture'] = (bool) $payload['final_capture'];
        }

        $response = $this->api(
            'POST',
            '/v2/checkout/orders/'.$orderId.'/capture',
            $body,
            $payload['idempotency_key'] ?? null,
        );

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal capture failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];
        $captureId = $this->extractCaptureId($order);

        return $this->mapSuccess(
            body: $order,
            raw: $response,
            transactionId: $captureId ?? $orderId,
            message: 'PayPal order captured.',
            status: isset($order['status']) ? (string) $order['status'] : 'COMPLETED',
            amount: $this->amountFromOrder($order) ?? (isset($payload['amount']) ? (int) $payload['amount'] : null),
            currency: $this->currencyFromOrder($order) ?? (isset($payload['currency']) ? (string) $payload['currency'] : null),
        );
    }

    public function refund(array $payload): PaymentResponse
    {
        $captureId = (string) ($payload['capture_id'] ?? $payload['payment_id'] ?? '');
        $this->requireKeys(['capture_id' => $captureId], 'capture_id');

        $body = [];

        if (isset($payload['amount'], $payload['currency'])) {
            $body['amount'] = [
                'value' => $this->formatMajorAmount((int) $payload['amount']),
                'currency_code' => strtoupper((string) $payload['currency']),
            ];
        } elseif (isset($payload['amount'])) {
            $body['amount'] = [
                'value' => $this->formatMajorAmount((int) $payload['amount']),
                'currency_code' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            ];
        }

        if (isset($payload['note_to_payer'])) {
            $body['note_to_payer'] = (string) $payload['note_to_payer'];
        }

        if (isset($payload['invoice_id'])) {
            $body['invoice_id'] = (string) $payload['invoice_id'];
        }

        $response = $this->api(
            'POST',
            '/v2/payments/captures/'.$captureId.'/refund',
            $body,
            $payload['idempotency_key'] ?? null,
        );

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal refund failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $captureId,
                status: 'refund_failed',
            );
        }

        /** @var array<string, mixed> $refund */
        $refund = $response['body'];

        return $this->mapSuccess(
            body: $refund,
            raw: $response,
            transactionId: isset($refund['id']) ? (string) $refund['id'] : $captureId,
            message: 'PayPal refund created.',
            status: isset($refund['status']) ? (string) $refund['status'] : 'COMPLETED',
            amount: isset($payload['amount']) ? (int) $payload['amount'] : $this->amountFromMoney($refund['amount'] ?? null),
            currency: isset($payload['currency'])
                ? strtoupper((string) $payload['currency'])
                : $this->currencyFromMoney($refund['amount'] ?? null),
        );
    }

    public function partialRefund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount');

        if (! isset($payload['capture_id']) && ! isset($payload['payment_id'])) {
            $this->requireKeys($payload, 'capture_id');
        }

        return $this->refund($payload);
    }

    public function status(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['payment_id'] ?? $payload['order_id'] ?? $payload['token'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $orderId], 'payment_id');

        $response = $this->api('GET', '/v2/checkout/orders/'.$orderId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'Unable to fetch PayPal order status.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];

        return $this->mapSuccess(
            body: $order,
            raw: $response,
            transactionId: isset($order['id']) ? (string) $order['id'] : $orderId,
            message: 'PayPal order status retrieved.',
            status: isset($order['status']) ? (string) $order['status'] : null,
            amount: $this->amountFromOrder($order),
            currency: $this->currencyFromOrder($order),
        );
    }

    public function cancel(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['payment_id'] ?? $payload['order_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $orderId], 'payment_id');

        // PayPal Checkout Orders cannot be cancelled via a dedicated endpoint.
        // Confirm the order is still CREATED, then treat it as cancelled locally.
        $response = $this->api('GET', '/v2/checkout/orders/'.$orderId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'Unable to cancel PayPal order.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];
        $status = (string) ($order['status'] ?? '');

        if ($status !== 'CREATED') {
            return $this->mapFailure(
                message: 'Only CREATED PayPal orders can be cancelled.',
                body: $order,
                raw: $response,
                transactionId: $orderId,
                status: $status,
            );
        }

        return $this->mapSuccess(
            body: array_merge($order, ['cancelled' => true]),
            raw: $response,
            transactionId: $orderId,
            message: 'PayPal order marked as cancelled (CREATED, unpaid).',
            status: 'cancelled',
            amount: $this->amountFromOrder($order),
            currency: $this->currencyFromOrder($order),
        );
    }

    public function verify(array $payload): PaymentResponse
    {
        $statusResponse = $this->status($payload);

        if (! $statusResponse->isSuccess()) {
            return $statusResponse;
        }

        $orderStatus = strtoupper((string) ($statusResponse->getStatus() ?? ''));

        if (! in_array($orderStatus, ['APPROVED', 'COMPLETED'], true)) {
            return $this->mapFailure(
                message: 'PayPal order is not APPROVED or COMPLETED.',
                body: $statusResponse->getData(),
                raw: $statusResponse->getRawResponse(),
                transactionId: $statusResponse->getGatewayTransactionId(),
                status: $orderStatus !== '' ? $orderStatus : 'unverified',
            );
        }

        return $this->mapSuccess(
            body: $statusResponse->getData(),
            raw: $statusResponse->getRawResponse(),
            transactionId: $statusResponse->getGatewayTransactionId(),
            message: 'PayPal payment verified.',
            status: $orderStatus,
            amount: $statusResponse->getAmount(),
            currency: $statusResponse->getCurrency(),
        );
    }

    public function verifySignature(array $payload): PaymentResponse
    {
        // PayPal return-URL flows pass a `token` (order id); there is no client HMAC like Razorpay.
        // Verification is a live status check against the Orders API.
        $orderId = (string) ($payload['token'] ?? $payload['payment_id'] ?? $payload['order_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['token' => $orderId], 'token');

        return $this->status([
            'payment_id' => $orderId,
        ]);
    }

    public function verifyWebhook(array $payload): PaymentResponse
    {
        $webhookId = $this->config['webhook_id'] ?? null;

        if (! is_string($webhookId) || $webhookId === '') {
            throw new WebhookVerificationException(
                message: 'PayPal webhook_id is not configured.',
                context: ['gateway' => $this->getName()],
            );
        }

        /** @var array<string, mixed> $headers */
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        $authAlgo = $this->headerValue($headers, 'PAYPAL-AUTH-ALGO');
        $certUrl = $this->headerValue($headers, 'PAYPAL-CERT-URL');
        $transmissionId = $this->headerValue($headers, 'PAYPAL-TRANSMISSION-ID');
        $transmissionSig = $this->headerValue($headers, 'PAYPAL-TRANSMISSION-SIG')
            ?: (string) ($payload['signature'] ?? '');
        $transmissionTime = $this->headerValue($headers, 'PAYPAL-TRANSMISSION-TIME');

        /** @var array<string, mixed> $webhookEvent */
        $webhookEvent = is_array($payload['payload'] ?? null)
            ? $payload['payload']
            : (is_array($payload['webhook_event'] ?? null) ? $payload['webhook_event'] : []);

        if ($authAlgo === '' || $certUrl === '' || $transmissionId === '' || $transmissionSig === '' || $transmissionTime === '') {
            throw new WebhookVerificationException(
                message: 'PayPal webhook verification headers are missing.',
                context: [
                    'gateway' => $this->getName(),
                    'has_auth_algo' => $authAlgo !== '',
                    'has_cert_url' => $certUrl !== '',
                    'has_transmission_id' => $transmissionId !== '',
                    'has_transmission_sig' => $transmissionSig !== '',
                    'has_transmission_time' => $transmissionTime !== '',
                ],
            );
        }

        if ($webhookEvent === []) {
            throw new WebhookVerificationException(
                message: 'PayPal webhook event payload is missing.',
                context: ['gateway' => $this->getName()],
            );
        }

        $verifyBody = [
            'auth_algo' => $authAlgo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId,
            'webhook_event' => $webhookEvent,
        ];

        $response = $this->api('POST', '/v1/notifications/verify-webhook-signature', $verifyBody);

        $verificationStatus = strtoupper((string) ($response['body']['verification_status'] ?? ''));

        if (! ($response['successful'] ?? false) || $verificationStatus !== 'SUCCESS') {
            throw new WebhookVerificationException(
                message: 'PayPal webhook signature verification failed.',
                context: [
                    'gateway' => $this->getName(),
                    'verification_status' => $verificationStatus !== '' ? $verificationStatus : null,
                    'body' => $response['body'] ?? [],
                ],
            );
        }

        $eventId = (string) ($webhookEvent['id'] ?? '');
        $eventType = (string) ($webhookEvent['event_type'] ?? 'unknown');
        $createTime = isset($webhookEvent['create_time'])
            ? strtotime((string) $webhookEvent['create_time'])
            : false;

        return $this->mapSuccess(
            body: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'timestamp' => $createTime !== false ? $createTime : null,
                'payload' => $webhookEvent,
                'signature_valid' => true,
                'verification_status' => $verificationStatus,
            ],
            raw: $response,
            transactionId: $this->extractWebhookResourceId($webhookEvent),
            message: 'PayPal webhook verified.',
            status: 'webhook_verified',
        );
    }

    public function paymentLink(array $payload): PaymentResponse
    {
        return $this->create($payload);
    }

    public function qr(array $payload): PaymentResponse
    {
        return $this->mapFailure(
            message: 'PayPal does not support QR payment generation through this gateway.',
            body: $payload,
            status: 'unsupported',
        );
    }

    public function createSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'plan_id');

        $body = array_filter([
            'plan_id' => (string) $payload['plan_id'],
            'quantity' => isset($payload['quantity']) ? (string) $payload['quantity'] : null,
            'custom_id' => isset($payload['custom_id']) ? (string) $payload['custom_id'] : null,
            'start_time' => isset($payload['start_time']) ? (string) $payload['start_time'] : null,
            'shipping_amount' => $payload['shipping_amount'] ?? null,
            'subscriber' => $payload['subscriber'] ?? null,
            'application_context' => $payload['application_context'] ?? null,
            'plan' => $payload['plan'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (! isset($body['application_context'])) {
            $applicationContext = array_filter([
                'return_url' => isset($payload['return_url']) ? (string) $payload['return_url'] : null,
                'cancel_url' => isset($payload['cancel_url']) ? (string) $payload['cancel_url'] : null,
                'brand_name' => isset($payload['brand_name']) ? (string) $payload['brand_name'] : null,
                'user_action' => isset($payload['user_action']) ? (string) $payload['user_action'] : 'SUBSCRIBE_NOW',
            ], static fn (mixed $v): bool => $v !== null);

            if ($applicationContext !== []) {
                $body['application_context'] = $applicationContext;
            }
        }

        if (! isset($body['subscriber']) && (isset($payload['email']) || isset($payload['name']))) {
            $subscriber = array_filter([
                'email_address' => isset($payload['email']) ? (string) $payload['email'] : null,
                'name' => isset($payload['name']) && is_array($payload['name'])
                    ? $payload['name']
                    : (isset($payload['name']) ? ['given_name' => (string) $payload['name']] : null),
            ], static fn (mixed $v): bool => $v !== null);

            if ($subscriber !== []) {
                $body['subscriber'] = $subscriber;
            }
        }

        $response = $this->api('POST', '/v1/billing/subscriptions', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal subscription creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $subscription */
        $subscription = $response['body'];
        $subscriptionId = isset($subscription['id']) ? (string) $subscription['id'] : null;
        $approveUrl = $this->findLink($subscription, 'approve');

        if ($approveUrl !== null) {
            return $this->mapRedirect(
                url: $approveUrl,
                body: $subscription,
                raw: $response,
                transactionId: $subscriptionId,
            );
        }

        return $this->mapSuccess(
            body: $subscription,
            raw: $response,
            transactionId: $subscriptionId,
            message: 'PayPal subscription created.',
            status: isset($subscription['status']) ? (string) $subscription['status'] : 'APPROVAL_PENDING',
        );
    }

    public function getSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        $response = $this->api('GET', '/v1/billing/subscriptions/'.$id);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal subscription not found.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'PayPal subscription retrieved.',
            status: (string) ($response['body']['status'] ?? 'retrieved'),
        );
    }

    public function cancelSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        $body = [
            'reason' => (string) ($payload['reason'] ?? 'Cancelled by merchant'),
        ];

        $response = $this->api('POST', '/v1/billing/subscriptions/'.$id.'/cancel', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal subscription cancel failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'] !== [] ? $response['body'] : ['id' => $id, 'status' => 'CANCELLED'],
            raw: $response,
            transactionId: $id,
            message: 'PayPal subscription cancelled.',
            status: (string) ($response['body']['status'] ?? 'CANCELLED'),
        );
    }

    public function pauseSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        $body = [
            'reason' => (string) ($payload['reason'] ?? 'Suspended by merchant'),
        ];

        $response = $this->api('POST', '/v1/billing/subscriptions/'.$id.'/suspend', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal subscription suspend failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'] !== [] ? $response['body'] : ['id' => $id, 'status' => 'SUSPENDED'],
            raw: $response,
            transactionId: $id,
            message: 'PayPal subscription suspended.',
            status: (string) ($response['body']['status'] ?? 'SUSPENDED'),
        );
    }

    public function resumeSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        $body = [
            'reason' => (string) ($payload['reason'] ?? 'Reactivated by merchant'),
        ];

        $response = $this->api('POST', '/v1/billing/subscriptions/'.$id.'/activate', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: $this->errorMessage($response['body'], 'PayPal subscription activate failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'] !== [] ? $response['body'] : ['id' => $id, 'status' => 'ACTIVE'],
            raw: $response,
            transactionId: $id,
            message: 'PayPal subscription activated.',
            status: (string) ($response['body']['status'] ?? 'ACTIVE'),
        );
    }

    /**
     * Obtain (and cache for this request lifecycle) an OAuth2 access token.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        $response = $this->http->request(
            method: 'POST',
            url: $this->baseUrl().'/v1/oauth2/token',
            headers: [
                'Accept' => 'application/json',
            ],
            form: [
                'grant_type' => 'client_credentials',
            ],
            username: $this->configString('client_id'),
            password: $this->configString('client_secret'),
        );

        $token = $response['body']['access_token'] ?? null;

        if (! ($response['successful'] ?? false) || ! is_string($token) || $token === '') {
            throw new PaymentFailedException(
                message: $this->errorMessage(
                    is_array($response['body'] ?? null) ? $response['body'] : [],
                    'PayPal OAuth token request failed.',
                ),
                context: [
                    'gateway' => $this->getName(),
                    'status' => $response['status'] ?? null,
                ],
            );
        }

        $this->accessToken = $token;

        return $this->accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>}
     */
    private function api(string $method, string $path, array $body = [], mixed $idempotencyKey = null): array
    {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: $method,
            url: $this->baseUrl().$path,
            headers: [
                'Authorization' => 'Bearer '.$this->accessToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json: $hasBody ? $body : ($method === 'GET' ? $body : null),
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return $response;
    }

    /**
     * Convert minor-unit integer amount to PayPal major-unit string (e.g. 1050 -> "10.50").
     */
    private function formatMajorAmount(int $minorAmount): string
    {
        return number_format($minorAmount / 100, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body, string $fallback): string
    {
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $message = $body['message'];

            if (isset($body['details'][0]['description']) && is_string($body['details'][0]['description'])) {
                return $message.': '.$body['details'][0]['description'];
            }

            return $message;
        }

        if (isset($body['details'][0]['description']) && is_string($body['details'][0]['description'])) {
            return $body['details'][0]['description'];
        }

        if (isset($body['error_description']) && is_string($body['error_description']) && $body['error_description'] !== '') {
            return $body['error_description'];
        }

        if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
            return $body['error'];
        }

        if (isset($body['name']) && is_string($body['name']) && $body['name'] !== '') {
            return $body['name'];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function findLink(array $resource, string $rel): ?string
    {
        $links = $resource['links'] ?? null;

        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            if (($link['rel'] ?? '') === $rel && isset($link['href']) && is_string($link['href']) && $link['href'] !== '') {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function extractCaptureId(array $order): ?string
    {
        $purchaseUnits = $order['purchase_units'] ?? null;

        if (! is_array($purchaseUnits)) {
            return null;
        }

        foreach ($purchaseUnits as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $captures = $unit['payments']['captures'] ?? null;

            if (! is_array($captures)) {
                continue;
            }

            foreach ($captures as $capture) {
                if (is_array($capture) && isset($capture['id']) && is_string($capture['id']) && $capture['id'] !== '') {
                    return $capture['id'];
                }
            }
        }

        return isset($order['id']) && is_string($order['id']) ? $order['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function amountFromOrder(array $order): ?int
    {
        $purchaseUnits = $order['purchase_units'] ?? null;

        if (! is_array($purchaseUnits) || ! isset($purchaseUnits[0]) || ! is_array($purchaseUnits[0])) {
            return null;
        }

        return $this->amountFromMoney($purchaseUnits[0]['amount'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function currencyFromOrder(array $order): ?string
    {
        $purchaseUnits = $order['purchase_units'] ?? null;

        if (! is_array($purchaseUnits) || ! isset($purchaseUnits[0]) || ! is_array($purchaseUnits[0])) {
            return null;
        }

        return $this->currencyFromMoney($purchaseUnits[0]['amount'] ?? null);
    }

    private function amountFromMoney(mixed $money): ?int
    {
        if (! is_array($money) || ! isset($money['value'])) {
            return null;
        }

        return (int) round(((float) $money['value']) * 100);
    }

    private function currencyFromMoney(mixed $money): ?string
    {
        if (! is_array($money) || ! isset($money['currency_code']) || ! is_string($money['currency_code'])) {
            return null;
        }

        return strtoupper($money['currency_code']);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                return (string) ($value[0] ?? '');
            }

            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $webhookEvent
     */
    private function extractWebhookResourceId(array $webhookEvent): ?string
    {
        $resource = $webhookEvent['resource'] ?? null;

        if (! is_array($resource)) {
            return null;
        }

        if (isset($resource['id']) && is_string($resource['id']) && $resource['id'] !== '') {
            return $resource['id'];
        }

        $supplementary = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        return is_string($supplementary) && $supplementary !== '' ? $supplementary : null;
    }
}
