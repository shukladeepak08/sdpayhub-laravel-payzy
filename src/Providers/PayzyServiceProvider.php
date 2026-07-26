<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sdpayhub\Payzy\Contracts\IdempotencyStore;
use Sdpayhub\Payzy\Factories\GatewayFactory;
use Sdpayhub\Payzy\Http\Controllers\WebhookController;
use Sdpayhub\Payzy\Services\CacheIdempotencyStore;
use Sdpayhub\Payzy\Services\DatabaseIdempotencyStore;
use Sdpayhub\Payzy\Services\IdempotencyService;
use Sdpayhub\Payzy\Services\PayzyManager;
use Sdpayhub\Payzy\Services\SecureHttpClient;
use Sdpayhub\Payzy\Services\WebhookReplayGuard;
use Sdpayhub\Payzy\Support\RetryPolicy;
use Sdpayhub\Payzy\Support\SecretMasker;

final class PayzyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/payzy.php', 'payzy');

        $this->app->singleton(SecretMasker::class, function (): SecretMasker {
            /** @var list<string> $keys */
            $keys = config('payzy.logging.mask_keys', []);

            return new SecretMasker($keys);
        });

        $this->app->singleton(SecureHttpClient::class, function ($app): SecureHttpClient {
            /** @var array<string, mixed> $timeouts */
            $timeouts = config('payzy.timeouts', []);
            /** @var array<string, mixed> $retries */
            $retries = config('payzy.retries', []);

            return new SecureHttpClient(
                connectTimeout: (int) ($timeouts['connect'] ?? 10),
                requestTimeout: (int) ($timeouts['request'] ?? 30),
                retryPolicy: RetryPolicy::fromConfig($retries),
                secretMasker: $app->make(SecretMasker::class),
                loggingEnabled: (bool) config('payzy.logging.enabled', true),
                logChannel: (string) config('payzy.logging.channel', 'stack'),
            );
        });

        $this->app->singleton(IdempotencyStore::class, function ($app): IdempotencyStore {
            $driver = (string) config('payzy.idempotency.driver', 'cache');

            if ($driver === 'database') {
                return new DatabaseIdempotencyStore;
            }

            $store = config('payzy.idempotency.cache_store');

            $cache = is_string($store) && $store !== ''
                ? $app['cache']->store($store)
                : $app['cache']->store();

            return new CacheIdempotencyStore($cache);
        });

        $this->app->singleton(IdempotencyService::class, function ($app): IdempotencyService {
            /** @var array<string, mixed> $config */
            $config = config('payzy.idempotency', []);

            return new IdempotencyService($app->make(IdempotencyStore::class), $config);
        });

        $this->app->singleton(WebhookReplayGuard::class, function ($app): WebhookReplayGuard {
            return new WebhookReplayGuard(
                cache: $app['cache']->store(),
                timestampToleranceSeconds: (int) config('payzy.webhooks.timestamp_tolerance_seconds', 300),
                nonceTtlSeconds: (int) config('payzy.webhooks.nonce_ttl_seconds', 86400),
            );
        });

        $this->app->singleton(GatewayFactory::class, function ($app): GatewayFactory {
            return new GatewayFactory($app->make(SecureHttpClient::class));
        });

        $this->app->singleton(PayzyManager::class, function ($app): PayzyManager {
            /** @var array<string, mixed> $config */
            $config = config('payzy', []);

            return new PayzyManager(
                factory: $app->make(GatewayFactory::class),
                idempotency: $app->make(IdempotencyService::class),
                config: $config,
            );
        });

        $this->app->alias(PayzyManager::class, 'payzy');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/payzy.php' => config_path('payzy.php'),
            ], 'payzy-config');

            $this->publishes([
                __DIR__.'/../../routes/webhooks.php' => base_path('routes/payzy-webhooks.php'),
            ], 'payzy-routes');

            $this->publishes([
                __DIR__.'/../../database/migrations/2024_01_01_000000_create_payzy_idempotency_keys_table.php' => database_path('migrations/2024_01_01_000000_create_payzy_idempotency_keys_table.php'),
            ], 'payzy-migrations');
        }

        $this->registerWebhookRoutes();
    }

    private function registerWebhookRoutes(): void
    {
        if (! (bool) config('payzy.webhooks.enabled', true)) {
            return;
        }

        $prefix = (string) config('payzy.webhooks.prefix', 'payzy/webhooks');
        /** @var list<string>|string $middleware */
        $middleware = config('payzy.webhooks.middleware', ['api']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function (): void {
                Route::post('{gateway}', WebhookController::class)
                    ->name('payzy.webhooks');
            });
    }
}
