<?php
/**
 * Testbed seed: adds the Observium data source and the demo maps: those
 * shipped with the application (examples/maps) and the map of the
 * simulated devices (demo/testbed.conf, DEMO_MAPS_EXTRA_DIR). Safe to run
 * again: the source is updated, an existing map is left as it is.
 *
 *   php seed.php   (in the weathermap container, as www-data)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

define('APP_ROOT', '/app');
define('PUBLIC_ROOT', APP_ROOT . '/public');
require APP_ROOT . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\DataSourceService;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\MapService;

const SOURCE_NAME = 'Observium (testbed)';

$config = new Config();
$database = new Database($config);
$sources = new DataSourceService($database);

$password = (string) getenv('TESTBED_OBSERVIUM_DB_PASSWORD');
if ($password === '') {
    fwrite(STDERR, "TESTBED_OBSERVIUM_DB_PASSWORD is not set\n");
    exit(1);
}

$existing = $sources->getSourceByName(SOURCE_NAME);

$source = [
    'name' => SOURCE_NAME,
    'type' => 'observium',
    // Observium's address in the browser, for the links to its port pages:
    // the public address when deploy.sh has one, else the one already set
    // (by hand in Admin > Data Sources), else the published port
    'url' => (string) (getenv('TESTBED_OBSERVIUM_URL') ?: (($existing['url'] ?? '') ?: getenv('TESTBED_OBSERVIUM_DEFAULT_URL'))),
    // the database password lives in settings; the source itself has none
    'password' => '',
    'settings' => [
        'mode' => 'mysql',
        'db_host' => getenv('TESTBED_OBSERVIUM_DB_HOST') ?: 'db',
        'db_port' => 3306,
        'db_name' => 'observium',
        'db_user' => 'weathermap',
        'db_password' => $sources->encodeSecret($password),
        'rrd_dir' => '/opt/observium/rrd',
        'rrdcached' => getenv('TESTBED_RRDCACHED') ?: 'rrdcached:42217',
    ],
];

if ($existing) {
    $sources->updateSource((int) $existing['id'], $source);
    echo "Data source updated: " . SOURCE_NAME . "\n";
} else {
    $sources->createSource($source);
    echo "Data source added: " . SOURCE_NAME . "\n";
}

// the demo maps: those of examples/maps, drawn from static values, and
// the map of the simulated devices (weathermap/demo/testbed.conf, through
// DEMO_MAPS_EXTRA_DIR), the same ones as the Maps page's demo button
$maps = new MapService($database, $config);
foreach ((new DemoMaps($maps, new GroupService($database)))->install() as $name => $added) {
    echo ($added ? 'Map added: ' : 'Map already there: ') . $name . "\n";
}
