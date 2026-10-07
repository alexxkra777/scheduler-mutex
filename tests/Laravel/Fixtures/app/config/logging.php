<?php

// Reported exceptions are collected by the tests, nothing should land in a log file.
return [
    'default' => 'null',
    'channels' => [
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
        'emergency' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
