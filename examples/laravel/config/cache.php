<?php

declare(strict_types=1);

// Laravel's scheduler wants a cache store; we never use its locks, so "array" is enough.
return [
    'default' => 'array',
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
    ],
    'prefix' => 'scheduler-demo',
];
