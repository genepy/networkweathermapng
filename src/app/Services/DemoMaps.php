<?php
/**
 * Demo maps shipped with the application (examples/maps/*.conf).
 *
 * Each demo shows features of the map format and the viewer, and reads its
 * traffic from the static data source (TARGET static:<in>:<out>), so it
 * draws without Zabbix or Observium. install() adds the demos that are not
 * there yet to a "Demo maps" group; a map that already has the name of a
 * demo is left as it is, so running it again changes nothing.
 *
 * An installation can add its own demos from an extra folder,
 * DEMO_MAPS_EXTRA_DIR: the testbed adds the map of its simulated devices
 * there, which reads its Observium source (F65).
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class DemoMaps
{
    public const GROUP = 'Demo maps';

    public function __construct(
        private MapService $maps,
        private GroupService $groups,
        private ?string $dir = null,
        private ?string $extraDir = null,
    ) {
    }

    /** The extra demo folder of this installation (DEMO_MAPS_EXTRA_DIR), null when none */
    public static function extraDir(): ?string
    {
        $dir = trim((string) Config::env('DEMO_MAPS_EXTRA_DIR'));
        return $dir !== '' && is_dir($dir) ? $dir : null;
    }

    /** Folder of the demo configurations */
    public static function defaultDir(): string
    {
        return dirname(__DIR__, 2) . '/examples/maps';
    }

    /**
     * The demo files, by map name (the TITLE of each file), sorted by file
     * name: the application's, then those of the extra folder.
     *
     * @return array<string, string> name => path
     */
    public function available(): array
    {
        $demos = [];
        foreach ([$this->dir ?? self::defaultDir(), $this->extraDir ?? self::extraDir()] as $dir) {
            if ($dir === null) {
                continue;
            }
            $files = glob($dir . '/*.conf') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = MapService::titleOf((string) file_get_contents($file)) ?? basename($file, '.conf');
                // a demo of the application keeps its name
                $demos[$name] ??= $file;
            }
        }
        return $demos;
    }

    /**
     * The demos no map is named after yet (what install() would add).
     *
     * @return list<string> map names
     */
    public function missing(): array
    {
        $existing = array_column($this->maps->getAllMaps(), 'name');
        return array_values(array_diff(array_keys($this->available()), $existing));
    }

    /**
     * Adds the missing demos to the demo group (created when needed).
     *
     * @return array<string, bool> map name => true when added, false when already there
     */
    public function install(): array
    {
        $existing = array_column($this->maps->getAllMaps(), 'name');
        $result = [];
        $groupId = null;

        foreach ($this->available() as $name => $file) {
            if (in_array($name, $existing, true)) {
                $result[$name] = false;
                continue;
            }
            $groupId ??= $this->groupId();
            $id = $this->maps->createMap(['name' => $name, 'group_id' => $groupId]);
            $this->maps->saveMapConfig($id, (string) file_get_contents($file));
            $result[$name] = true;
        }

        return $result;
    }

    private function groupId(): int
    {
        foreach ($this->groups->getAllGroups() as $group) {
            if ($group['name'] === self::GROUP) {
                return (int) $group['id'];
            }
        }
        return $this->groups->createGroup(['name' => self::GROUP]);
    }
}
