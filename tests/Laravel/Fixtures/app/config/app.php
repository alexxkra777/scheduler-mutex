<?php

// Bare minimum for a console-only test app. No env() on purpose, so the
// shell environment can't change what the tests see.
return [
    'name' => 'scheduler-test-fixture',
    'env' => 'testing',
    'debug' => false,
    'timezone' => 'UTC',
    'maintenance' => [
        'driver' => 'file',
    ],
];
