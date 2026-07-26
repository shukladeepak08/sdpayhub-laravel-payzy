<?php

declare(strict_types=1);
use Sdpayhub\Payzy\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');
