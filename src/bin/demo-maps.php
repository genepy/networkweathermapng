<?php
/**
 * NetworkWeathermapNG - demo maps
 *
 *   php bin/demo-maps.php          add the demo maps that are not there yet
 *   php bin/demo-maps.php --list   list the demo maps without adding them
 *
 * The demos (examples/maps/*.conf) go to a "Demo maps" group. They read
 * their traffic from the static data source, so they draw without Zabbix
 * or Observium. A map that already has the name of a demo is left as it
 * is, so running this again changes nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');

$autoload = APP_ROOT . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    $autoload = dirname(APP_ROOT) . '/vendor/autoload.php';
}
require_once $autoload;

$envRoot = file_exists(APP_ROOT . '/.env') || !file_exists(dirname(APP_ROOT) . '/.env') ? APP_ROOT : dirname(APP_ROOT);
Dotenv\Dotenv::createImmutable($envRoot)->safeLoad();

use App\Core\Config;
use App\Core\Database;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\MapService;

$config = new Config();
$database = new Database($config);
$demos = new DemoMaps(new MapService($database, $config), new GroupService($database));

if (in_array('--list', $argv, true)) {
    foreach ($demos->available() as $name => $file) {
        echo $name . '  (' . basename($file) . ")\n";
    }
    exit(0);
}

foreach ($demos->install() as $name => $added) {
    echo ($added ? 'Map added: ' : 'Map already there: ') . $name . "\n";
}
