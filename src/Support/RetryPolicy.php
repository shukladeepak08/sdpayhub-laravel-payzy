<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Support;

/**
 * Exponential backoff with optional jitter for HTTP retries.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $times,
        private readonly int $sleepMilliseconds,
        private readonly float $multiplier,
        private readonly bool $jitter,
    ) {}

    /**
     * @return list<int> Sleep durations in milliseconds for each retry attempt
     */
    public function sleepSchedule(): array
    {
        $schedule = [];
        $delay = $this->sleepMilliseconds;

        for ($i = 0; $i < max(0, $this->times); $i++) {
            $sleep = (int) round($delay);

            if ($this->jitter && $sleep > 0) {
                $sleep += random_int(0, (int) max(1, $sleep * 0.25));
            }

            $schedule[] = $sleep;
            $delay *= $this->multiplier;
        }

        return $schedule;
    }

    public function times(): int
    {
        return max(0, $this->times);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            times: (int) ($config['times'] ?? 3),
            sleepMilliseconds: (int) ($config['sleep_milliseconds'] ?? 200),
            multiplier: (float) ($config['multiplier'] ?? 2.0),
            jitter: (bool) ($config['jitter'] ?? true),
        );
    }
}
