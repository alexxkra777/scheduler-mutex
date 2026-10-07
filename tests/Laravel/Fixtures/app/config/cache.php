<?php

// schedule:run asks for a cache repository; an in-memory one is enough.
return [
    'default' => 'array',
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
    ],
    'prefix' => 'scheduler-test',
];
