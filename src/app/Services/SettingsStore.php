<?php

namespace App\Services;

use App\Core\Database;

/**
 * Named values of the settings table, global (map_id 0) or for one map.
 */
class SettingsStore
{
    public function __construct(private Database $db)
    {
    }

    public function get(int $mapId, string $name): ?string
    {
        $row = $this->db->queryOne('SELECT value FROM settings WHERE map_id = ? AND name = ?', [$mapId, $name]);
        return $row === null ? null : (string) $row['value'];
    }

    /**
     * Values of the given settings for each map that has one, by map id.
     *
     * @param string[] $names
     * @param int[] $mapIds
     * @return array<int, array<string, string>>
     */
    public function getMany(array $names, array $mapIds): array
    {
        if (!$names || !$mapIds) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT map_id, name, value FROM settings WHERE name IN ('
                . implode(', ', array_fill(0, count($names), '?')) . ') AND map_id IN ('
                . implode(', ', array_fill(0, count($mapIds), '?')) . ')',
            array_merge(array_values($names), array_map('intval', array_values($mapIds)))
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['map_id']][(string) $row['name']] = (string) $row['value'];
        }
        return $values;
    }

    public function set(int $mapId, string $name, string $value): void
    {
        if ($this->db->update('settings', ['value' => $value], 'map_id = ? AND name = ?', [$mapId, $name]) === 0
            && $this->get($mapId, $name) === null) {
            $this->db->insert('settings', ['map_id' => $mapId, 'name' => $name, 'value' => $value]);
        }
    }

    public function remove(int $mapId, string $name): void
    {
        $this->db->delete('settings', 'map_id = ? AND name = ?', [$mapId, $name]);
    }
}
