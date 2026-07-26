<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sdpayhub\Payzy\Providers\PayzyServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PayzyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('payzy.default', 'razorpay');
        $app['config']->set('payzy.mode', 'sandbox');
        $app['config']->set('payzy.currency', 'INR');
        $app['config']->set('payzy.logging.enabled', false);
        $app['config']->set('payzy.idempotency.enabled', true);
        $app['config']->set('payzy.idempotency.driver', 'cache');
        $app['config']->set('payzy.webhooks.enabled', true);
        $app['config']->set('cache.default', 'array');

        $app['config']->set('payzy.gateways.razorpay', [
            'key' => 'rzp_test_key',
            'secret' => 'rzp_test_secret',
            'webhook_secret' => 'whsec_test',
            'base_url' => 'https://api.razorpay.com/v1',
        ]);

        $app['config']->set('payzy.gateways.stripe', [
            'secret' => 'sk_test_secret',
            'webhook_secret' => 'whsec_stripe_test',
            'base_url' => 'https://api.stripe.com/v1',
            'api_version' => '2024-06-20',
        ]);

        $app['config']->set('payzy.gateways.paypal', [
            'client_id' => 'paypal_client',
            'client_secret' => 'paypal_secret',
            'webhook_id' => 'WH-TEST',
            'base_url' => 'https://api-m.sandbox.paypal.com',
        ]);

        $app['config']->set('payzy.gateways.paytm', [
            'merchant_id' => 'PAYTM_MID',
            'merchant_key' => 'PAYTM_KEY',
            'website' => 'WEBSTAGING',
            'industry_type' => 'Retail',
            'channel_id' => 'WEB',
            'base_url' => 'https://securegw-stage.paytm.in',
        ]);

        $app['config']->set('payzy.gateways.phonepe', [
            'client_id' => 'phonepe_client',
            'client_secret' => 'phonepe_secret',
            'client_version' => '1',
            'merchant_id' => 'PHONEPE_MID',
            'salt_key' => 'saltkey',
            'salt_index' => '1',
            'base_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
            'oauth_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        ]);
    }
}
