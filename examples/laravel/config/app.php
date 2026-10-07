<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

return [
    'name' => 'scheduler-demo',
    'env' => 'local',
    'debug' => false,
    // app timezone stays UTC on purpose; every task carries its own timezone
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => 'base64:' . base64_encode(str_repeat('0', 32)),
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file'],
    'providers' => ServiceProvider::defaultProviders()->toArray(),
];
