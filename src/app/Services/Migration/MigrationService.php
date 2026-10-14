<?php
/**
 * Turns a reviewed migration into a map, and back. Used by the admin pages
 * and by bin/migrate.php.
 */

declare(strict_types=1);

namespace App\Services\Migration;

use App\Core\Config;
use App\Services\DataSourceService;
use App\Services\Editor\ConfigHistory;
use App\Services\MapService;

class MigrationService
{
    public function __construct(
        private Config $config,
        private MapService $maps,
        private DataSourceService $dataSources,
        private MigrationStore $store
    ) {
    }

    public function migrator(array $meta, bool $match = true): MapMigrator
    {
        $matcher = null;
        if ($match && !empty($meta['sourceId']) && $this->dataSources->getSource((int) $meta['sourceId'])) {
            $matcher = new TargetMatcher(new DataSourceDirectory($this->dataSources, (int) $meta['sourceId']));
        }
        return new MapMigrator($this->config->getPublicPath(), $matcher);
    }

    public function analyze(array $meta, bool $match = true): array
    {
        return $this->migrator($meta, $match)->analyze($this->store->original($meta['id']));
    }

    /** The .conf the map gets, with the choices saved in $meta */
    public function converted(array $meta, ?MapMigrator $migrator = null): string
    {
        $migrator ??= $this->migrator($meta);
        $original = $this->store->original($meta['id']);
        $report = $migrator->analyze($original);
        return $migrator->convert($original, $report, (array) ($meta['choices'] ?? []), ['fonts' => !empty($meta['fonts'])]);
    }

    /** The uploaded file, less what cannot work here: INCLUDE and the old output paths */
    public function original(array $meta): string
    {
        return $this->migrator($meta, false)->convert($this->store->original($meta['id']), ['images' => [], 'targets' => []]);
    }

    /**
     * Creates the map (or updates the one created before) with the converted
     * file. The original is put in the map's history: one undo away.
     *
     * @return array the map row
     */
    public function apply(array $meta): array
    {
        $content = $this->converted($meta);

        $map = !empty($meta['mapId']) ? $this->maps->getMap((int) $meta['mapId']) : null;
        if (!$map) {
            $id = $this->maps->createMap([
                'name' => $meta['name'],
                'group_id' => $meta['groupId'] ?? 1,
                'active' => 1,
            ]);
            $map = $this->maps->getMap($id);
        }

        $this->maps->saveMapConfig((int) $map['id'], $content);
        $this->history($map)->record($this->original($meta));

        $this->store->update($meta['id'], ['mapId' => (int) $map['id'], 'applied' => date('Y-m-d H:i:s')]);
        return $map;
    }

    /** Puts the original back in place of the converted map; undo returns to the converted one */
    public function rollback(array $meta): ?array
    {
        $map = !empty($meta['mapId']) ? $this->maps->getMap((int) $meta['mapId']) : null;
        if (!$map) {
            return null;
        }

        $file = $this->config->getConfigsPath() . '/' . $map['config_file'];
        $this->history($map)->record(is_file($file) ? (string) file_get_contents($file) : null);
        $this->maps->saveMapConfig((int) $map['id'], $this->original($meta));
        return $map;
    }

    private function history(array $map): ConfigHistory
    {
        return new ConfigHistory(
            $this->config->getDataRoot() . '/history/' . (int) $map['id'],
            $this->config->getConfigsPath() . '/' . $map['config_file']
        );
    }
}
