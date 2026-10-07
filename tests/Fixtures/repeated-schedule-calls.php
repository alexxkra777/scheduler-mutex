<?php

declare(strict_types=1);

// Calls `schedule:run` twice through one application/kernel, the way a long-running worker or a
// test harness would, and prints what each attempt saw. Used by RepeatedScheduleCallTest.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../../examples/laravel/bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
        $exitCode = $kernel->call('schedule:run');
        echo "attempt=$attempt exit=$exitCode events=" . count($app->make(Schedule::class)->events()) . "\n";
    } catch (Throwable $e) {
        echo "attempt=$attempt exception=" . $e::class . "\n";
    }
}
