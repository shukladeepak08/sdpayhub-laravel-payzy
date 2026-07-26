<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sdpayhub\Payzy\DTOs\PaymentResponse;
use Sdpayhub\Payzy\DTOs\WebhookPayload;
use Sdpayhub\Payzy\Events\PaymentFailed;
use Sdpayhub\Payzy\Events\PaymentSuccess;
use Sdpayhub\Payzy\Events\RefundCompleted;

/**
 * Queued webhook processor so slow handlers never block the gateway HTTP response.
 */
final class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}

    public function handle(): void
    {
        $eventType = strtolower($this->payload->eventType);
        $response = PaymentResponse::success(
            data: $this->payload->toArray(),
            rawResponse: $this->payload->payload,
            gatewayTransactionId: $this->extractTransactionId(),
            message: 'Webhook processed.',
            status: $eventType,
            meta: ['gateway' => $this->payload->gateway, 'event_id' => $this->payload->eventId],
        );

        if ($this->isSuccessEvent($eventType)) {
            event(new PaymentSuccess($this->payload->gateway, $response));

            return;
        }

        if ($this->isFailureEvent($eventType)) {
            event(new PaymentFailed($this->payload->gateway, $response));

            return;
        }

        if ($this->isRefundEvent($eventType)) {
            event(new RefundCompleted($this->payload->gateway, $response));
        }
    }

    private function extractTransactionId(): ?string
    {
        $payload = $this->payload->payload;

        $candidates = [
            $payload['id'] ?? null,
            $payload['payment_id'] ?? null,
            data_get($payload, 'payload.payment.entity.id'),
            data_get($payload, 'data.object.id'),
            data_get($payload, 'resource.id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function isSuccessEvent(string $eventType): bool
    {
        return str_contains($eventType, 'captured')
            || str_contains($eventType, 'paid')
            || str_contains($eventType, 'succeeded')
            || str_contains($eventType, 'completed')
            || str_contains($eventType, 'payment.success');
    }

    private function isFailureEvent(string $eventType): bool
    {
        return str_contains($eventType, 'failed')
            || str_contains($eventType, 'canceled')
            || str_contains($eventType, 'cancelled');
    }

    private function isRefundEvent(string $eventType): bool
    {
        return str_contains($eventType, 'refund');
    }
}
