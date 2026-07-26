<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Gateways\Stripe;

use Sdpayhub\Payzy\Contracts\SupportsCustomers;
use Sdpayhub\Payzy\Contracts\SupportsSubscriptions;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Gateways\AbstractGateway;
use Sdpayhub\Payzy\Support\Signature;

/**
 * Production-ready Stripe gateway implementation.
 *
 * Communicates with the Stripe REST API using form-encoded requests
 * (application/x-www-form-urlencoded) authenticated with a secret key
 * bearer token. Every public method returns a unified {@see PaymentResponse}.
 *
 * Supported capabilities:
 * - PaymentIntents (create, capture, cancel, retrieve/status, verify)
 * - Refunds (full and partial)
 * - Checkout Sessions (payment links)
 * - Customers (create, get, update, delete)
 * - Subscriptions (create, get, cancel, pause, resume)
 * - Webhook signature verification (Stripe-Signature scheme)
 */
final class StripeGateway extends AbstractGateway implements SupportsCustomers, SupportsSubscriptions
{
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfigKeys(): array
    {
        return ['secret'];
    }

    public function create(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'currency');

        $body = [
            'amount' => (int) $payload['amount'],
            'currency' => strtolower((string) $payload['currency']),
        ];

        if (isset($payload['customer']) && $payload['customer'] !== '') {
            $body['customer'] = (string) $payload['customer'];
        }

        if (isset($payload['description'])) {
            $body['description'] = (string) $payload['description'];
        }

        if (isset($payload['payment_method'])) {
            $body['payment_method'] = (string) $payload['payment_method'];
        }

        if (isset($payload['return_url'])) {
            $body['return_url'] = (string) $payload['return_url'];
        }

        if (array_key_exists('confirm', $payload)) {
            $body['confirm'] = $payload['confirm'] ? 'true' : 'false';
        }

        if (isset($payload['capture_method'])) {
            $body['capture_method'] = (string) $payload['capture_method'];
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        if (isset($payload['receipt_email'])) {
            $body['receipt_email'] = (string) $payload['receipt_email'];
        }

        $response = $this->api('POST', '/payment_intents', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe payment intent creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $response['body'];

        return $this->mapSuccess(
            body: $intent,
            raw: $response,
            transactionId: isset($intent['id']) ? (string) $intent['id'] : null,
            message: 'Stripe payment intent created.',
            status: isset($intent['status']) ? (string) $intent['status'] : 'requires_payment_method',
            amount: isset($intent['amount']) ? (int) $intent['amount'] : (int) $payload['amount'],
            currency: isset($intent['currency']) ? (string) $intent['currency'] : (string) $payload['currency'],
        );
    }

    public function capture(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'payment_id');

        $paymentId = (string) $payload['payment_id'];
        $body = [];

        if (isset($payload['amount'])) {
            $body['amount_to_capture'] = (int) $payload['amount'];
        }

        $response = $this->api('POST', '/payment_intents/'.$paymentId.'/capture', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe payment capture failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $response['body'];

        return $this->mapSuccess(
            body: $intent,
            raw: $response,
            transactionId: isset($intent['id']) ? (string) $intent['id'] : $paymentId,
            message: 'Stripe payment captured.',
            status: isset($intent['status']) ? (string) $intent['status'] : 'succeeded',
            amount: isset($intent['amount_received']) ? (int) $intent['amount_received'] : (isset($intent['amount']) ? (int) $intent['amount'] : null),
            currency: isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
    }

    public function refund(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'payment_id');

        $paymentId = (string) $payload['payment_id'];
        $body = [
            'payment_intent' => $paymentId,
        ];

        if (isset($payload['amount'])) {
            $body['amount'] = (int) $payload['amount'];
        }

        if (isset($payload['reason'])) {
            $body['reason'] = (string) $payload['reason'];
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        $response = $this->api('POST', '/refunds', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe refund failed.'),
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
            message: 'Stripe refund created.',
            status: isset($refund['status']) ? (string) $refund['status'] : 'succeeded',
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

        $response = $this->api('GET', '/payment_intents/'.$paymentId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Unable to fetch Stripe payment status.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $response['body'];

        return $this->mapSuccess(
            body: $intent,
            raw: $response,
            transactionId: isset($intent['id']) ? (string) $intent['id'] : $paymentId,
            message: 'Stripe payment status retrieved.',
            status: isset($intent['status']) ? (string) $intent['status'] : null,
            amount: isset($intent['amount']) ? (int) $intent['amount'] : null,
            currency: isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
    }

    public function cancel(array $payload): PaymentResponse
    {
        $paymentId = (string) ($payload['payment_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $paymentId], 'payment_id');

        $body = [];

        if (isset($payload['cancellation_reason'])) {
            $body['cancellation_reason'] = (string) $payload['cancellation_reason'];
        }

        $response = $this->api('POST', '/payment_intents/'.$paymentId.'/cancel', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Unable to cancel Stripe payment intent.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $response['body'];

        return $this->mapSuccess(
            body: $intent,
            raw: $response,
            transactionId: isset($intent['id']) ? (string) $intent['id'] : $paymentId,
            message: 'Stripe payment intent cancelled.',
            status: isset($intent['status']) ? (string) $intent['status'] : 'canceled',
            amount: isset($intent['amount']) ? (int) $intent['amount'] : null,
            currency: isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
    }

    public function verify(array $payload): PaymentResponse
    {
        $paymentId = (string) ($payload['payment_id'] ?? $payload['id'] ?? '');
        $this->requireKeys(['payment_id' => $paymentId], 'payment_id');

        $response = $this->api('GET', '/payment_intents/'.$paymentId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Unable to verify Stripe payment.'),
                body: $response['body'],
                raw: $response,
                transactionId: $paymentId,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $response['body'];
        $status = isset($intent['status']) ? (string) $intent['status'] : '';

        if ($status !== 'succeeded') {
            return $this->mapFailure(
                message: sprintf('Stripe payment is not succeeded (status: %s).', $status !== '' ? $status : 'unknown'),
                body: $intent,
                raw: $response,
                transactionId: $paymentId,
                status: $status !== '' ? $status : 'unverified',
            );
        }

        return $this->mapSuccess(
            body: $intent,
            raw: $response,
            transactionId: isset($intent['id']) ? (string) $intent['id'] : $paymentId,
            message: 'Stripe payment verified.',
            status: 'succeeded',
            amount: isset($intent['amount']) ? (int) $intent['amount'] : null,
            currency: isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
    }

    public function verifySignature(array $payload): PaymentResponse
    {
        $rawBody = (string) ($payload['raw_body'] ?? '');
        $signature = (string) ($payload['signature'] ?? $payload['stripe_signature'] ?? '');

        // Webhook-style verification when a raw body and Stripe-Signature header are supplied.
        if ($rawBody !== '' && $signature !== '') {
            return $this->verifyWebhook($payload);
        }

        // Client-side confirmation: ensure the client_secret belongs to the payment intent.
        $paymentId = (string) ($payload['payment_intent'] ?? $payload['payment_id'] ?? $payload['id'] ?? '');
        $clientSecret = (string) ($payload['client_secret'] ?? '');

        if ($paymentId !== '' && $clientSecret !== '') {
            $matches = str_starts_with($clientSecret, $paymentId.'_secret_');

            if (! $matches) {
                return $this->mapFailure(message: 'Stripe client secret does not match the payment intent.', body: ['payment_intent' => $paymentId],
                    status: 'signature_invalid',
                );
            }

            return $this->verify(['payment_id' => $paymentId]);
        }

        // Fallback: verify by retrieving the payment intent status.
        $this->requireKeys(['payment_id' => $paymentId], 'payment_id');

        return $this->verify(['payment_id' => $paymentId]);
    }

    public function verifyWebhook(array $payload): PaymentResponse
    {
        $rawBody = (string) ($payload['raw_body'] ?? '');
        $signatureHeader = (string) ($payload['signature'] ?? $payload['stripe_signature'] ?? '');
        $webhookSecret = $this->config['webhook_secret'] ?? null;

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            throw new WebhookVerificationException(
                message: 'Stripe webhook_secret is not configured.',
                context: ['gateway' => $this->getName()],
            );
        }

        if ($rawBody === '' || $signatureHeader === '') {
            throw new WebhookVerificationException(
                message: 'Stripe webhook payload or signature is missing.',
                context: ['gateway' => $this->getName()],
            );
        }

        $parsed = $this->parseStripeSignature($signatureHeader);
        $timestamp = $parsed['timestamp'];
        $signatures = $parsed['signatures'];

        if ($timestamp === null || $signatures === []) {
            throw new WebhookVerificationException(
                message: 'Malformed Stripe-Signature header.',
                context: ['gateway' => $this->getName()],
            );
        }

        $expected = Signature::hmacSha256($timestamp.'.'.$rawBody, $webhookSecret);

        $matched = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            throw new WebhookVerificationException(
                message: 'Invalid Stripe webhook signature.',
                context: ['gateway' => $this->getName()],
            );
        }

        /** @var array<string, mixed> $event */
        $event = is_array($payload['payload'] ?? null)
            ? $payload['payload']
            : (json_decode($rawBody, true) ?: []);

        $eventId = (string) ($event['id'] ?? hash('sha256', $rawBody));
        $eventType = (string) ($event['type'] ?? 'unknown');
        $eventCreated = isset($event['created']) ? (int) $event['created'] : (int) $timestamp;

        return $this->mapSuccess(
            body: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'timestamp' => $eventCreated,
                'payload' => $event,
                'signature_valid' => true,
            ],
            raw: $event,
            transactionId: isset($event['data']['object']['id']) ? (string) $event['data']['object']['id'] : null,
            message: 'Stripe webhook verified.',
            status: 'webhook_verified',
        );
    }

    public function paymentLink(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'amount', 'currency', 'success_url', 'cancel_url');

        $body = [
            'mode' => 'payment',
            'success_url' => (string) $payload['success_url'],
            'cancel_url' => (string) $payload['cancel_url'],
            'line_items' => [
                [
                    'quantity' => (int) ($payload['quantity'] ?? 1),
                    'price_data' => [
                        'currency' => strtolower((string) $payload['currency']),
                        'unit_amount' => (int) $payload['amount'],
                        'product_data' => [
                            'name' => (string) ($payload['description'] ?? $payload['name'] ?? 'Payment'),
                        ],
                    ],
                ],
            ],
        ];

        if (isset($payload['customer']) && $payload['customer'] !== '') {
            $body['customer'] = (string) $payload['customer'];
        } elseif (isset($payload['customer_email'])) {
            $body['customer_email'] = (string) $payload['customer_email'];
        }

        if (isset($payload['client_reference_id'])) {
            $body['client_reference_id'] = (string) $payload['client_reference_id'];
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        $response = $this->api('POST', '/checkout/sessions', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe checkout session creation failed.'),
                body: $response['body'],
                raw: $response,
            );
        }

        /** @var array<string, mixed> $session */
        $session = $response['body'];
        $url = isset($session['url']) ? (string) $session['url'] : null;

        if ($url !== null && $url !== '') {
            return $this->mapRedirect(
                url: $url,
                body: $session,
                raw: $response,
                transactionId: isset($session['id']) ? (string) $session['id'] : null,
                amount: (int) $payload['amount'],
                currency: (string) $payload['currency'],
            );
        }

        return $this->mapSuccess(
            body: $session,
            raw: $response,
            transactionId: isset($session['id']) ? (string) $session['id'] : null,
            message: 'Stripe checkout session created.',
            status: isset($session['status']) ? (string) $session['status'] : 'created',
            amount: (int) $payload['amount'],
            currency: (string) $payload['currency'],
        );
    }

    public function qr(array $payload): PaymentResponse
    {
        return $this->mapFailure(message: 'Stripe does not support native QR payments.', body: $payload,
            status: 'unsupported',
        );
    }

    public function createCustomer(array $payload): PaymentResponse
    {
        $body = array_filter([
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? $payload['contact'] ?? null,
            'description' => $payload['description'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        $response = $this->api('POST', '/customers', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe customer creation failed.'),
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
            message: 'Stripe customer created.',
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
                message: (string) ($response['body']['error']['message'] ?? 'Stripe customer not found.'),
                body: $response['body'],
                raw: $response,
                transactionId: $customerId,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $customerId,
            message: 'Stripe customer retrieved.',
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
            'phone' => $payload['phone'] ?? $payload['contact'] ?? null,
            'description' => $payload['description'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        $response = $this->api('POST', '/customers/'.$customerId, $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe customer update failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $customerId,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $customerId,
            message: 'Stripe customer updated.',
            status: 'updated',
        );
    }

    public function deleteCustomer(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'customer_id');
        $customerId = (string) $payload['customer_id'];
        $response = $this->api('DELETE', '/customers/'.$customerId);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe customer deletion failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $customerId,
            );
        }

        /** @var array<string, mixed> $customer */
        $customer = $response['body'];

        return $this->mapSuccess(
            body: $customer,
            raw: $response,
            transactionId: $customerId,
            message: 'Stripe customer deleted.',
            status: (bool) ($customer['deleted'] ?? false) ? 'deleted' : 'delete_requested',
        );
    }

    public function createSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'customer', 'price_id');

        $body = [
            'customer' => (string) $payload['customer'],
            'items' => [
                [
                    'price' => (string) $payload['price_id'],
                    'quantity' => (int) ($payload['quantity'] ?? 1),
                ],
            ],
        ];

        if (isset($payload['trial_period_days'])) {
            $body['trial_period_days'] = (int) $payload['trial_period_days'];
        }

        if (isset($payload['default_payment_method'])) {
            $body['default_payment_method'] = (string) $payload['default_payment_method'];
        }

        if (isset($payload['collection_method'])) {
            $body['collection_method'] = (string) $payload['collection_method'];
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        $response = $this->api('POST', '/subscriptions', $body, $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe subscription creation failed.'),
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
            message: 'Stripe subscription created.',
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
                message: (string) ($response['body']['error']['message'] ?? 'Stripe subscription not found.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Stripe subscription retrieved.',
            status: (string) ($response['body']['status'] ?? 'retrieved'),
        );
    }

    public function cancelSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        if (($payload['at_period_end'] ?? false) === true) {
            $response = $this->api('POST', '/subscriptions/'.$id, [
                'cancel_at_period_end' => 'true',
            ], $payload['idempotency_key'] ?? null);
        } else {
            $response = $this->api('DELETE', '/subscriptions/'.$id);
        }

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe subscription cancel failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Stripe subscription cancelled.',
            status: (string) ($response['body']['status'] ?? 'canceled'),
        );
    }

    public function pauseSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        $pauseCollection = [
            'behavior' => (string) ($payload['behavior'] ?? 'mark_uncollectible'),
        ];

        if (isset($payload['resumes_at'])) {
            $pauseCollection['resumes_at'] = (int) $payload['resumes_at'];
        }

        $response = $this->api('POST', '/subscriptions/'.$id, [
            'pause_collection' => $pauseCollection,
        ], $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe subscription pause failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Stripe subscription paused.',
            status: 'paused',
        );
    }

    public function resumeSubscription(array $payload): PaymentResponse
    {
        $this->requireKeys($payload, 'subscription_id');
        $id = (string) $payload['subscription_id'];

        // Clearing pause_collection resumes normal billing on the subscription.
        $response = $this->api('POST', '/subscriptions/'.$id, [
            'pause_collection' => '',
        ], $payload['idempotency_key'] ?? null);

        if (! ($response['successful'] ?? false)) {
            return $this->mapFailure(
                message: (string) ($response['body']['error']['message'] ?? 'Stripe subscription resume failed.'),
                body: $response['body'],
                raw: $response,
                transactionId: $id,
            );
        }

        return $this->mapSuccess(
            body: $response['body'],
            raw: $response,
            transactionId: $id,
            message: 'Stripe subscription resumed.',
            status: (string) ($response['body']['status'] ?? 'active'),
        );
    }

    /**
     * Parse a Stripe-Signature header of the form "t=timestamp,v1=sig[,v1=sig...]".
     *
     * @return array{timestamp: ?string, signatures: list<string>}
     */
    private function parseStripeSignature(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    /**
     * Send a request to the Stripe API.
     *
     * Stripe expects form-encoded (application/x-www-form-urlencoded) bodies for
     * write operations, and query parameters for reads.
     *
     * @param  array<string, mixed>  $body
     * @return array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>}
     */
    private function api(string $method, string $path, array $body = [], mixed $idempotencyKey = null): array
    {
        $method = strtoupper($method);

        $headers = [
            'Authorization' => 'Bearer '.$this->configString('secret'),
        ];

        $apiVersion = $this->config['api_version'] ?? null;

        if (is_string($apiVersion) && $apiVersion !== '') {
            $headers['Stripe-Version'] = $apiVersion;
        }

        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        /** @var array{status:int,successful:bool,body:array<string,mixed>,headers:array<string,mixed>} $response */
        $response = $this->http->request(
            method: $method,
            url: $this->baseUrl().$path,
            headers: $headers,
            json: $isWrite ? null : ($body === [] ? null : $body),
            form: $isWrite ? $body : null,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return $response;
    }
}
