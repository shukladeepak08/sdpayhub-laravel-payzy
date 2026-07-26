<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sdpayhub\Payzy\Exceptions\GatewayTimeoutException;
use Sdpayhub\Payzy\Exceptions\PaymentFailedException;
use Sdpayhub\Payzy\Exceptions\PayzyException;
use Sdpayhub\Payzy\Support\RetryPolicy;
use Sdpayhub\Payzy\Support\SecretMasker;
use Throwable;

/**
 * Secure Laravel HTTP client wrapper with retries, timeouts, and secret masking.
 *
 * Never disables SSL verification.
 */
final class SecureHttpClient
{
    public function __construct(
        private readonly int $connectTimeout,
        private readonly int $requestTimeout,
        private readonly RetryPolicy $retryPolicy,
        private readonly SecretMasker $secretMasker,
        private readonly bool $loggingEnabled,
        private readonly string $logChannel,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>|null  $form
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?array $form = null,
        ?string $idempotencyKey = null,
        ?string $username = null,
        ?string $password = null,
    ): array {
        $pending = $this->baseRequest($headers, $username, $password);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $pending = $pending->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $this->log('debug', 'Outgoing payment gateway request', [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $this->secretMasker->mask($headers),
            'json' => $json !== null ? $this->secretMasker->mask($json) : null,
            'form' => $form !== null ? $this->secretMasker->mask($form) : null,
        ]);

        try {
            $response = $this->send($pending, $method, $url, $json, $form);
        } catch (ConnectionException $exception) {
            throw new GatewayTimeoutException(
                message: 'Payment gateway connection timed out or failed.',
                previous: $exception,
                context: ['url' => $url, 'method' => $method],
            );
        } catch (PayzyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PaymentFailedException(
                message: 'Unexpected payment gateway HTTP error: '.$exception->getMessage(),
                previous: $exception,
                context: ['url' => $url, 'method' => $method],
            );
        }

        $body = $this->decodeBody($response);

        $this->log('debug', 'Payment gateway response received', [
            'status' => $response->status(),
            'body' => $this->secretMasker->mask($body),
        ]);

        if ($response->serverError()) {
            throw new PaymentFailedException(
                message: 'Payment gateway returned a server error.',
                context: ['status' => $response->status(), 'body' => $body, 'url' => $url],
            );
        }

        return [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $body,
            'headers' => $response->headers(),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function baseRequest(array $headers, ?string $username, ?string $password): PendingRequest
    {
        $pending = Http::timeout($this->requestTimeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->acceptJson()
            ->retry(
                $this->retryPolicy->times(),
                function (int $attempt): int {
                    $schedule = $this->retryPolicy->sleepSchedule();

                    return $schedule[$attempt - 1] ?? ($schedule[array_key_last($schedule)] ?? 200);
                },
                function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status();

                        return $status !== null && ($status === 429 || $status >= 500);
                    }

                    return false;
                },
            );

        if ($username !== null) {
            $pending = $pending->withBasicAuth($username, $password ?? '');
        }

        return $pending;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>|null  $form
     */
    private function send(PendingRequest $pending, string $method, string $url, ?array $json, ?array $form): Response
    {
        $method = strtoupper($method);

        return match ($method) {
            'GET' => $pending->get($url, $json ?? []),
            'DELETE' => $pending->delete($url, $json ?? []),
            'POST' => $form !== null ? $pending->asForm()->post($url, $form) : $pending->asJson()->post($url, $json ?? []),
            'PUT' => $form !== null ? $pending->asForm()->put($url, $form) : $pending->asJson()->put($url, $json ?? []),
            'PATCH' => $form !== null ? $pending->asForm()->patch($url, $form) : $pending->asJson()->patch($url, $json ?? []),
            default => throw new PaymentFailedException('Unsupported HTTP method: '.$method),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response): array
    {
        $json = $response->json();

        if (is_array($json)) {
            /** @var array<string, mixed> $json */
            return $json;
        }

        $body = $response->body();

        return $body === '' ? [] : ['raw' => $body];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (! $this->loggingEnabled) {
            return;
        }

        Log::channel($this->logChannel)->{$level}('[Payzy] '.$message, $context);
    }
}
