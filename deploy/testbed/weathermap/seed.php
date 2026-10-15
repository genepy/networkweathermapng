<?php
/**
 * Testbed seed: adds the Observium data source and a demo map of the
 * simulated devices. Safe to run again: the source is updated, an existing
 * map is left as it is.
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
use App\Services\MapService;

const SOURCE_NAME = 'Observium (testbed)';
const MAP_NAME = 'Testbed';

$config = new Config();
$database = new Database($config);
$sources = new DataSourceService($database);

$password = (string) getenv('TESTBED_OBSERVIUM_DB_PASSWORD');
if ($password === '') {
    fwrite(STDERR, "TESTBED_OBSERVIUM_DB_PASSWORD is not set\n");
    exit(1);
}

$source = [
    'name' => SOURCE_NAME,
    'type' => 'observium',
    'url' => '',
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

$existing = $sources->getSourceByName(SOURCE_NAME);
if ($existing) {
    $sources->updateSource((int) $existing['id'], $source);
    echo "Data source updated: " . SOURCE_NAME . "\n";
} else {
    $sources->createSource($source);
    echo "Data source added: " . SOURCE_NAME . "\n";
}

$maps = new MapService($database, $config);
$names = array_column($maps->getAllMaps(), 'name');
if (in_array(MAP_NAME, $names, true)) {
    echo "Map already there: " . MAP_NAME . "\n";
    exit(0);
}

$id = $maps->createMap(['name' => MAP_NAME, 'width' => 900, 'height' => 560]);
$content = (string) $maps->getMapConfig($id);
$content = substr($content, 0, (int) strpos($content, '# Add your nodes and links below'));
$content = str_replace('KEYPOS DEFAULT 10 10', 'KEYPOS DEFAULT 10 40', $content);
$content .= (string) file_get_contents(__DIR__ . '/demo-map.conf');
$maps->saveMapConfig($id, $content);
echo "Map added: " . MAP_NAME . "\n";
