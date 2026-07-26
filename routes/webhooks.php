<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sdpayhub\Payzy\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Payzy Webhook Routes
|--------------------------------------------------------------------------
|
| These routes are registered automatically by the service provider.
| Publish this file if you need to customize webhook routing.
|
*/

Route::post('{gateway}', WebhookController::class)->name('payzy.webhooks');
