<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways\Razorpay;

use Sdpayhub\Payzy\Contracts\SupportsCustomers;
use Sdpayhub\Payzy\Contracts\SupportsSubscriptions;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Gateways\AbstractGateway;
use Sdpayhub\Payzy\Support\Signature;

/**
 * Complete Razorpay gateway implementation.
 */
final class RazorpayGateway extends AbstractGateway implements SupportsCustomers, SupportsSubscriptions
{
    public function getName(): string
    {
        return 'razorpay';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfigKeys(): array
    {
        return ['key', 'secret'];
    }

    public function create(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'currency');

        $body = [
            'amount' => (int) $payload['amount'],
            'currency' => strtoupper((string) $payload['currency']),
            'receipt' => (string) ($payload['order_id'] ?? $payload['receipt'] ?? uniqid('rcpt_', true)),
            'payment_capture' => (int) ($payload['payment_capture'] ?? 1),
        ];

        if (isset($payload['notes']) && is_array($payload['notes'])) {
            $body['notes'] = $payload['notes'];
        }

        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $body['notes'] = array_merge($body['notes'] ?? [], [
                'customer_email' => $payload['customer']['email'] ?? null,
                'customer_phone' => $payload['customer']['phone'] ?? null,
                'customer_name' => $payload['customer']['name'] ?? null,
            ]);
        }

        $response = $this->api('POST', '/orders', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay order creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];

        return $this->mapSuccess(
            body: $order,
            raw: $response,
            transactionId: isset($order['id']) ? (string) $order['id'] : null,
            message: 'Razorpay order created.',
            status: isset($order['status']) ? (string) $order['status'] : 'created',
            amount: isset($order['amount']) ? (int) $order['amount'] : (int) $payload['amount'],
            currency: isset($order['currency']) ? (string) $order['currency'] : (string) $payload['currency'],
        );
    }

    public function capture(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'payment_id', 'amount');

        $paymentId = (string) $payload['payment_id'];
        $body = [
            'amount' => (int) $payload['amount'],
            'currency' => strtoupper((string) ($payload['currency'] ?? 'INR')),
        ];

        $response = $this->api('POST', '/payments/'.$paymentId.'/capture', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay capture failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $payment */
        $payment = $response['body'];

        return $this->mapSuccess(
            body: $payment,
            raw: $response,
            transactionId: isset($payment['id']) ? (string) $payment['id'] : $paymentId,
            message: 'Razorpay payment captured.',
            status: isset($payment['status']) ? (string) $payment['status'] : 'captured',
            amount: isset($payment['amount']) ? (int) $payment['amount'] : (int) $payload['amount'],
            currency: isset($payment['currency']) ? (string) $payment['currency'] : null,
        );
    }

    public function refund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'payment_id');

        $paymentId = (string) $payload['payment_id'];
        $body = [];

        if (isset($payload['amount'])) {
            $body['amount'] = (int) $payload['amount'];
        }

        if (isset($payload['notes']) && is_array($payload['notes'])) {
            $body['notes'] = $payload['notes'];
        }

        $response = $this->api('POST', '/payments/'.$paymentId.'/refund', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay refund failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
                status: 'refund_failed',
            );
        }

        /** @var array<string, mixed> $refund */
        $refund = $response['body'];

        return $this->mapSuccess(
            body: $refund,
            raw: $response,
            transactionId: isset($refund['id']) ? (string) $refund['id'] : $paymentId,
            message: 'Razorpay refund created.',
            status: isset($refund['status']) ? (string) $refund['status'] : 'processed',
            amount: isset($refund['amount']) ? (int) $refund['amount'] : null,
            currency: isset($refund['currency']) ? (string) $refund['currency'] : null,
        );
    }

    public function partialRefund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'payment_id', 'amount');

        return $this->refund($payload);
    }

    public function status(array $payload): PaymentResponse
    {
        $paymentId = (string) ($payload['payment_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $paymentId], 'payment_id');

        $response = $this->api('GET', '/payments/'.$paymentId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Unable to fetch Razorpay payment status.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $payment */
        $payment = $response['body'];

        return $this->mapSuccess(
            body: $payment,
            raw: $response,
            transactionId: isset($payment['id']) ? (string) $payment['id'] : $paymentId,
            message: 'Razorpay payment status retrieved.',
            status: isset($payment['status']) ? (string) $payment['status'] : null,
            amount: isset($payment['amount']) ? (int) $payment['amount'] : null,
            currency: isset($payment['currency']) ? (string) $payment['currency'] : null,
        );
    }

    public function cancel(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['payment_id'] ?? '');
        $this->requireKeys(['order_id' => $orderId], 'order_id');

        // Razorpay does not support cancelling orders via a dedicated cancel endpoint.
        // We mark as cancelled locally after confirming the order is still unpaid.
        $response = $this->api('GET', '/orders/'.$orderId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Unable to cancel Razorpay order.'),
                body: $response['body'],
                raw: $response,
                transactionId: $orderId,
            );
        }

        /** @var array<string, mixed> $order */
        $order = $response['body'];
        $status = (string) ($order['status'] ?? '');

        if (! in_array($status, ['created', 'attempted'], true)) {
            return $this->mapFailure(
                message: 'Only unpaid Razorpay orders can be cancelled.',
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
            message: 'Razorpay order marked as cancelled (unpaid).',
            status: 'cancelled',
            amount: isset($order['amount']) ? (int) $order['amount'] : null,
            currency: isset($order['currency']) ? (string) $order['currency'] : null,
        );
    }

    public function verify(array $payload): PaymentResponse
    {
        $signatureCheck = $this->verifySignature($payload);

        if (! $signatureCheck->isSuccess()) {
            return $signatureCheck;
        }

        $paymentId = (string) ($payload['payment_id'] ?? $payload['razorpay_payment_id'] ?? '');

        if ($paymentId === '') {
            return $signatureCheck;
        }

        return $this->status(['payment_id' => $paymentId]);
    }

    public function verifySignature(array $payload): PaymentResponse
    {
        $orderId = (string) ($payload['order_id'] ?? $payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['payment_id'] ?? $payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['signature'] ?? $payload['razorpay_signature'] ?? '');

        $this->requireKeys([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'signature' => $signature,
        ], 'order_id', 'payment_id', 'signature');

        $expected = Signature::hmacSha256($orderId.'|'.$paymentId, $this->configString('secret'));

        if (! Signature::equals($expected, $signature)) {
            return $this->mapFailure(
                message: 'Invalid Razorpay payment signature.',
                body: ['order_id' => $orderId, 'payment_id' => $paymentId],
                status: 'signature_invalid',
            );
        }

        return $this->mapSuccess(
            body: [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'signature_valid' => true,
            ],
            raw: [],
            transactionId: $paymentId,
            message: 'Razorpay signature verified.',
            status: 'verified',
        );
    }

    public function verifyWebhook(array $payload): PaymentResponse
    {
        $rawBody = (string) ($payload['raw_body'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');
        $webhookSecret = $this->config['webhook_secret'] ?? null;

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            throw new WebhookVerificationException(
                message: 'Razorpay webhook_secret is not configured.',
                context: ['gateway' => $this->getName()],
            );
        }

        if ($rawBody === '' || $signature === '') {
            throw new WebhookVerificationException(
                message: 'Razorpay webhook payload or signature is missing.',
                context: ['gateway' => $this->getName()],
            );
        }

        $expected = Signature::hmacSha256($rawBody, $webhookSecret);

        if (! Signature::equals($expected, $signature)) {
            throw new WebhookVerificationException(
                message: 'Invalid Razorpay webhook signature.',
                context: ['gateway' => $this->getName()],
            );
        }

        /** @var array<string, mixed> $body */
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $eventId = (string) ($body['event_id'] ?? $body['id'] ?? hash('sha256', $rawBody));
        $eventType = (string) ($body['event'] ?? 'unknown');
        $createdAt = isset($body['created_at']) ? (int) $body['created_at'] : null;

        return $this->mapSuccess(
            body: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'timestamp' => $createdAt,
                'payload' => $body,
                'signature_valid' => true,
            ],
            raw: $body,
            transactionId: data_get($body, 'payload.payment.entity.id') !== null
                ? (string) data_get($body, 'payload.payment.entity.id')
                : null,
            message: 'Razorpay webhook verified.',
            status: 'webhook_verified',
        );
    }

    public function paymentLink(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'currency');

        $body = [
            'amount' => (int) $payload['amount'],
            'currency' => strtoupper((string) $payload['currency']),
            'accept_partial' => (bool) ($payload['accept_partial'] ?? false),
            'description' => (string) ($payload['description'] ?? 'Payment'),
            'reference_id' => (string) ($payload['order_id'] ?? uniqid('plink_', true)),
        ];

        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $body['customer'] = array_filter([
                'name' => $payload['customer']['name'] ?? null,
                'email' => $payload['customer']['email'] ?? null,
                'contact' => $payload['customer']['phone'] ?? $payload['customer']['contact'] ?? null,
            ]);
        }

        if (isset($payload['callback_url'])) {
            $body['callback_url'] = (string) $payload['callback_url'];
            $body['callback_method'] = (string) ($payload['callback_method'] ?? 'get');
        }

        $response = $this->api('POST', '/payment_links', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay payment link creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $link */
        $link = $response['body'];
        $url = isset($link['short_url']) ? (string) $link['short_url'] : null;

        if ($url !== null) {
            return $this->mapRedirect(
                url: $url,
                body: $link,
                raw: $response,
                transactionId: isset($link['id']) ? (string) $link['id'] : null,
                amount: (int) $payload['amount'],
                currency: (string) $payload['currency'],
            );
        }

        return $this->mapSuccess(
            body: $link,
            raw: $response,
            transactionId: isset($link['id']) ? (string) $link['id'] : null,
            message: 'Razorpay payment link created.',
            status: isset($link['status']) ? (string) $link['status'] : 'created',
            amount: (int) $payload['amount'],
            currency: (string) $payload['currency'],
        );
    }

    public function qr(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount');

        $body = [
            'type' => (string) ($payload['type'] ?? 'upi_qr'),
            'name' => (string) ($payload['name'] ?? 'Payzy QR'),
            'usage' => (string) ($payload['usage'] ?? 'single_use'),
            'fixed_amount' => true,
            'payment_amount' => (int) $payload['amount'],
            'description' => (string) ($payload['description'] ?? 'QR Payment'),
            'close_by' => (int) ($payload['close_by'] ?? (time() + 3600)),
        ];

        $response = $this->api('POST', '/payments/qr_codes', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay QR creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $qr */
        $qr = $response['body'];

        return $this->mapSuccess(
            body: $qr,
            raw: $response,
            transactionId: isset($qr['id']) ? (string) $qr['id'] : null,
            message: 'Razorpay QR code created.',
            status: isset($qr['status']) ? (string) $qr['status'] : 'active',
            amount: (int) $payload['amount'],
            currency: (string) ($payload['currency'] ?? 'INR'),
        );
    }

    public function createCustomer(array $payload): PaymentResponse
    {
        $body = array_filter([
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'contact' => $payload['phone'] ?? $payload['contact'] ?? null,
            'fail_existing' => $payload['fail_existing'] ?? '0',
            'notes' => $payload['notes'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        $response = $this->api('POST', '/customers', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay customer creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $customer */
        $customer = $response['body'];

        return $this->mapSuccess(
            body: $customer,
            raw: $response,
            transactionId: isset($customer['id']) ? (string) $customer['id'] : null,
            message: 'Razorpay customer created.',
            status: 'created',
        );
    }

    public function getCustomer(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'customer_id');
        $customerId = (string) $payload['customer_id'];
        $response = $this->api('GET', '/customers/'.$customerId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay customer not found.'),
                body: $response['body'],
                raw: $response,
                transactionId: $customerId,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $customerId,
            message: 'Razorpay customer retrieved.',
            status: 'retrieved',
        );
    }

    public function updateCustomer(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'customer_id');
        $customerId = (string) $payload['customer_id'];
        $body = array_filter([
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'contact' => $payload['phone'] ?? $payload['contact'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        $response = $this->api('PUT', '/customers/'.$customerId, $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay customer update failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $customerId,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $customerId,
            message: 'Razorpay customer updated.',
            status: 'updated',
        );
    }

    public function deleteCustomer(array $payload): PaymentResponse
    {
        // Razorpay does not support deleting customers; return a consistent failure response.
        return $this->mapFailure(
            message: 'Razorpay does not support deleting customers.',
            body: $payload,
            status: 'unsupported',
        );
    }

    public function createSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'plan_id');

        $body = array_filter([
            'plan_id' => (string) $payload['plan_id'],
            'total_count' => $payload['total_count'] ?? null,
            'quantity' => $payload['quantity'] ?? 1,
            'customer_notify' => $payload['customer_notify'] ?? 1,
            'notes' => $payload['notes'] ?? null,
            'notify_info' => $payload['notify_info'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        $response = $this->api('POST', '/subscriptions', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay subscription creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $subscription */
        $subscription = $response['body'];

        return $this->mapSuccess(
            body: $subscription,
            raw: $response,
            transactionId: isset($subscription['id']) ? (string) $subscription['id'] : null,
            message: 'Razorpay subscription created.',
            status: isset($subscription['status']) ? (string) $subscription['status'] : 'created',
        );
    }

    public function getSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];
        $response = $this->api('GET', '/subscriptions/'.$id);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay subscription not found.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Razorpay subscription retrieved.',
            status: (string) ($response['body']['status'] ?? 'retrieved'),
        );
    }

    public function cancelSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];
        $body = [
            'cancel_at_cycle_end' => (int) ($payload['cancel_at_cycle_end'] ?? 0),
        ];

        $response = $this->api('POST', '/subscriptions/'.$id.'/cancel', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay subscription cancel failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Razorpay subscription cancelled.',
            status: (string) ($response['body']['status'] ?? 'cancelled'),
        );
    }

    public function pauseSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];
        $body = [
            'pause_at' => (string) ($payload['pause_at'] ?? 'now'),
        ];

        $response = $this->api('POST', '/subscriptions/'.$id.'/pause', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay subscription pause failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Razorpay subscription paused.',
            status: (string) ($response['body']['status'] ?? 'paused'),
        );
    }

    public function resumeSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];
        $body = [
            'resume_at' => (string) ($payload['resume_at'] ?? 'now'),
        ];

        $response = $this->api('POST', '/subscriptions/'.$id.'/resume', $body);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['description'] ?? 'Razorpay subscription resume failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Razorpay subscription resumed.',
            status: (string) ($response['body']['status'] ?? 'active'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>}
     */
    private function api(string $method, string $path, array $body = [], mixed $idempotencyKey = null): array
    {
        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: $method,
            url: $this->baseUrl().$path,
            headers: [
                'Content-Type' => 'application/json',
            ],
            json: in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) ? $body : ($method === 'GET' ? $body : null),
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            username: $this->configString('key'),
            password: $this->configString('secret'),
        );

        return $response;
    }
}
