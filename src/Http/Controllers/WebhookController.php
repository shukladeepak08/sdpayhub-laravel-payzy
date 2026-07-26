<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sdpayhub\Payzy\DTOs\WebhookPayload;
use Sdpayhub\Payzy\Events\WebhookFailed;
use Sdpayhub\Payzy\Events\WebhookReceived;
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Exceptions\WebhookVerificationException;
use Sdpayhub\Payzy\Jobs\ProcessWebhookJob;
use Sdpayhub\Payzy\Services\PayzyManager;
use Sdpayhub\Payzy\Services\WebhookReplayGuard;
use Throwable;

final class WebhookController extends Controller
{
    public function __construct(
        private readonly PayzyManager $payments,
        private readonly WebhookReplayGuard $replayGuard,
    ) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtolower($gateway);
        $rawBody = $request->getContent();
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        /** @var array<string, list<string|null>> $headers */
        $headers = $request->headers->all();

        try {
            $this->payments->using($gateway);

            $verification = $this->payments->verifyWebhook([
                'payload' => $payload,
                'raw_body' => $rawBody,
                'headers' => $this->flattenHeaders($headers),
                'signature' => $this->extractSignature($request, $gateway),
            ]);

            if (! $verification->isSuccess()) {
                throw new WebhookVerificationException(
                    message: $verification->getMessage() ?? 'Webhook verification failed.',
                    context: ['gateway' => $gateway],
                );
            }

            $data = $verification->getData();
            $eventId = (string) ($data['event_id'] ?? $data['id'] ?? '');
            $eventType = (string) ($data['event_type'] ?? $data['event'] ?? 'unknown');
            $timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : $this->extractTimestamp($request, $gateway);

            $this->replayGuard->assertFresh($timestamp, $eventId, $gateway);

            $webhookPayload = new WebhookPayload(
                gateway: $gateway,
                eventId: $eventId,
                eventType: $eventType,
                payload: is_array($data['payload'] ?? null) ? $data['payload'] : $payload,
                headers: $this->flattenHeaders($headers),
                timestamp: $timestamp,
            );

            event(new WebhookReceived($gateway, $webhookPayload));

            $queue = config('payzy.webhooks.queue', 'default');
            $connection = config('payzy.webhooks.queue_connection');

            $job = ProcessWebhookJob::dispatch($webhookPayload);

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }

            $job->onQueue(is_string($queue) ? $queue : 'default');

            return response()->json([
                'success' => true,
                'message' => 'Webhook accepted.',
                'event_id' => $eventId,
            ], 202);
        } catch (WebhookVerificationException $exception) {
            event(new WebhookFailed($gateway, $exception->getMessage()));

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (PayzyException $exception) {
            event(new WebhookFailed($gateway, $exception->getMessage()));

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            event(new WebhookFailed($gateway, $exception->getMessage()));

            return response()->json([
                'success' => false,
                'message' => 'Unable to process webhook.',
            ], 500);
        }
    }

    /**
     * @param  array<string, list<string|null>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $key => $values) {
            $flat[$key] = (string) ($values[0] ?? '');
        }

        return $flat;
    }

    private function extractSignature(Request $request, string $gateway): ?string
    {
        return match ($gateway) {
            'razorpay' => $request->header('X-Razorpay-Signature'),
            'stripe' => $request->header('Stripe-Signature'),
            'paypal' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'paytm' => $request->input('CHECKSUMHASH') ?? $request->header('X-Paytm-Checksum'),
            'phonepe' => $request->header('X-VERIFY') ?? $request->header('Authorization'),
            default => $request->header('X-Signature'),
        };
    }

    private function extractTimestamp(Request $request, string $gateway): ?int
    {
        return match ($gateway) {
            'stripe' => $this->parseStripeTimestamp((string) $request->header('Stripe-Signature', '')),
            'paypal' => $this->parsePayPalTimestamp((string) $request->header('PAYPAL-TRANSMISSION-TIME', '')),
            default => null,
        };
    }

    private function parseStripeTimestamp(string $header): ?int
    {
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function parsePayPalTimestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }
}
