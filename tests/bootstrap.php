<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Never inherit the application's database when running a test suite.
if (getenv('DB_HOST') !== false) {
    if (getenv('APP_ENV') !== 'test' || !preg_match('/^jobradar_test(?:_[a-z0-9_]+)?$/D', (string) getenv('DB_NAME'))) {
        throw new RuntimeException('Integrační testy vyžadují APP_ENV=test a samostatnou DB_NAME=jobradar_test[_suffix].');
    }
}
