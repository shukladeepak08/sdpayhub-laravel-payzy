<?php

declare(strict_types=1);

use Sdpayhub\Payzy\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
|
| Compatible with Pest 2 (Laravel 10) and Pest 3 (Laravel 11+).
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
