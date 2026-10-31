<?php
/**
 * NetworkWeathermapNG - map recorder
 *
 *   php bin/record.php          run until stopped (the Docker image runs it)
 *   php bin/record.php --once   one pass, for cron or a test
 *
 * Draws the maps whose recording is switched on (Admin > Map recording)
 * every recording interval and keeps the frames for the retention time, so
 * the viewer can play them back.
 *
 * It only runs while there is something to record: when recording is
 * turned off or no map is switched on, it exits at once (or at its next
 * pass). The web application starts it again when a map is switched on
 * (Admin > Map recording) or when a recorded map is viewed. Only one
 * recorder runs at a time (a lock in DATA_ROOT/recordings).
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
use App\Services\MapService;
use App\Services\RecordingService;

// icons and fonts in the maps are relative to the web root
chdir(PUBLIC_ROOT);

$once = in_array('--once', $argv, true);
$config = new Config();
$database = new Database($config);
$maps = new MapService($database, $config);
$recording = new RecordingService($database, $config);

$lock = $once ? null : $recording->lockRecorder();
if (!$once && $lock === null) {
    // another recorder is running
    exit(0);
}

$draw = static function (int $id) use ($maps): ?array {
    try {
        return $maps->recordFrame($id);
    } catch (\Throwable $e) {
        fwrite(STDERR, date('c') . " map $id: " . $e->getMessage() . "\n");
        return null;
    }
};

if (!$once && $recording->isActive()) {
    fwrite(STDOUT, date('c') . " recorder started\n");
}

do {
    // a last pass even when stopping, to delete the frames past the retention
    $written = $recording->recordDue($draw);
    if ($written > 0) {
        fwrite(STDOUT, date('c') . " $written frame(s) recorded\n");
    }
    if ($once) {
        break;
    }
    if (!$recording->isActive()) {
        fwrite(STDOUT, date('c') . " nothing to record, recorder stopped\n");
        break;
    }
    sleep(20);
} while (true);
