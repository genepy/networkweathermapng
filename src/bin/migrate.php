<?php
/**
 * NetworkWeathermapNG - migrate Weathermap maps from the command line
 *
 *   php bin/migrate.php [options] <file.conf|folder|file.zip>...
 *
 *   --source=ID   data source where the old targets are looked up
 *   --fonts       replace the bitmap fonts by TrueType fonts (default)
 *   --no-fonts    keep the bitmap fonts
 *   --group=ID    map group for the new maps (default 1)
 *   --apply       create the maps; without it the files are only analysed
 *   --json        print the reports as JSON
 *
 * Targets found with a single match are converted; the others keep their old
 * value and are listed, to choose in Admin > Migration.
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
use App\Services\DataSourceService;
use App\Services\MapService;
use App\Services\Migration\MigrationImporter;
use App\Services\Migration\MigrationService;
use App\Services\Migration\MigrationStore;

$options = ['source' => null, 'fonts' => true, 'group' => 1, 'apply' => false, 'json' => false];
$inputs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--source=(\d+)$/', $arg, $m)) {
        $options['source'] = (int) $m[1];
    } elseif (preg_match('/^--group=(\d+)$/', $arg, $m)) {
        $options['group'] = (int) $m[1];
    } elseif ($arg === '--fonts' || $arg === '--no-fonts') {
        $options['fonts'] = $arg === '--fonts';
    } elseif ($arg === '--apply' || $arg === '--json') {
        $options[substr($arg, 2)] = true;
    } elseif ($arg === '-h' || $arg === '--help') {
        $inputs = [];
        break;
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option $arg\n");
        exit(2);
    } else {
        $inputs[] = $arg;
    }
}

if (!$inputs) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $doc);
    echo preg_replace('/^\s*\* ?/m', '', trim($doc[1])) . "\n";
    exit($argc > 1 && !in_array($argv[1], ['-h', '--help'], true) ? 2 : 0);
}

$files = [];
foreach ($inputs as $input) {
    if (is_dir($input)) {
        foreach (glob(rtrim($input, '/') . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $files[basename($f)] = $f;
            }
        }
    } elseif (is_file($input)) {
        $files[basename($input)] = $input;
    } else {
        fwrite(STDERR, "Not found: $input\n");
        exit(2);
    }
}

$config = new Config();
$database = new Database($config);
require_once $config->getLibPath() . '/WeatherMap/bootstrap.php';
$GLOBALS['weathermap_error_suppress'] = ['WMWARN41'];

$dataSources = new DataSourceService($database);
if ($options['source'] !== null && !$dataSources->getSource($options['source'])) {
    fwrite(STDERR, "No data source {$options['source']}\n");
    exit(2);
}

$store = new MigrationStore($config->getDataRoot() . '/migration');
$service = new MigrationService($config, new MapService($database, $config), $dataSources, $store);
$imported = (new MigrationImporter($store, $config->getPublicPath()))->import($files);

foreach ($imported['skipped'] as $s) {
    fwrite(STDERR, "skipped: $s\n");
}
foreach ($imported['images'] as $i) {
    fwrite(STDERR, "image added: $i\n");
}

$all = [];
$exit = 0;
foreach ($imported['migrations'] as $id) {
    $meta = $store->update($id, ['sourceId' => $options['source'], 'fonts' => $options['fonts'], 'groupId' => $options['group']]);
    $report = $service->analyze($meta);

    $choices = [];
    $todo = [];
    foreach ($report['targets'] as $t) {
        if ($t['match']['status'] === 'matched') {
            $choices[$t['key']] = array_filter([
                'target' => $t['match']['choice']['target'],
                'datasource' => $t['match']['choice']['datasource'] ?? null,
            ]);
        } else {
            $choices[$t['key']] = 'keep';
            if ($t['match']['status'] !== 'keep' || $t['parsed']['kind'] === 'external') {
                $todo[] = $t;
            }
        }
    }
    $meta = $store->update($id, ['choices' => $choices]);

    $map = null;
    if ($options['apply']) {
        try {
            $map = $service->apply($meta);
        } catch (Throwable $e) {
            fwrite(STDERR, "{$meta['file']}: {$e->getMessage()}\n");
            $exit = 1;
        }
    }

    if ($options['json']) {
        $all[] = ['migration' => $id, 'file' => $meta['file'], 'mapId' => $map['id'] ?? null, 'report' => $report];
        continue;
    }

    printf("%s  \"%s\"  %d nodes, %d links%s\n", $meta['file'], $meta['name'], $report['counts']['nodes'], $report['counts']['links'],
        $map ? '  -> map ' . $map['id'] : '');
    foreach ($report['removed'] as $r) {
        echo "  removed: $r\n";
    }
    foreach ($report['images'] as $img) {
        if ($img['status'] !== 'ok') {
            echo "  image {$img['status']}: {$img['path']}" . ($img['new'] ? " -> {$img['new']}" : '') . "\n";
        }
    }
    if ($report['fonts']) {
        echo '  bitmap fonts ' . implode(', ', $report['fonts']) . ($options['fonts'] ? ': replaced' : ': kept') . "\n";
    }
    $converted = count(array_filter($choices, 'is_array'));
    echo "  targets: $converted converted, " . count($todo) . " to choose\n";
    foreach ($todo as $t) {
        echo "    {$t['kind']} {$t['name']}: {$t['raw']} ({$t['match']['status']}" . ($t['match']['note'] ? ', ' . $t['match']['note'] : '') . ")\n";
    }
    echo "  review: /admin/migration/$id\n";
}

if ($options['json']) {
    echo json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
exit($exit);
