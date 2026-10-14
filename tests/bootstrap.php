<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/lib/WeatherMap/bootstrap.php';

define('TESTS_ROOT', __DIR__);
define('TESTS_OUTPUT', dirname(__DIR__) . '/build/test-output');

if (!is_dir(TESTS_OUTPUT)) {
    mkdir(TESTS_OUTPUT, 0755, true);
}
