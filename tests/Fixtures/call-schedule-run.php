<?php

declare(strict_types=1);

// Runs the demo schedule the programmatic way (Artisan::call) instead of the CLI,
// to prove the lazy registration doesn't depend on argv.

use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../../examples/laravel/bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

exit($kernel->call('schedule:run'));
