<?php
/**
 * NetworkWeathermapNG - every setting of one map in one place, for the Map
 * settings panel (UI redesign step 5): time zone and kiosk (Display &
 * kiosk), recording, and who sees the map (Access, read only). The admin
 * pages Time zone, Kiosk mode and Map recording keep the global values and
 * the tables that set many maps at once; both write the same settings.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

final class MapSettings
{
    private TimezoneService $timezones;
    private KioskService $kiosk;
    private RecordingService $recording;

    public function __construct(private Database $db, private Config $config)
    {
        $this->timezones = TimezoneService::fromConfig($db, $config);
        $this->kiosk = new KioskService($db);
        $this->recording = new RecordingService($db, $config);
    }

    /**
     * The values the panel shows for a map.
     *
     * @return array{timezone: string, defaultZone: string, kioskZoom: int, kioskDuration: ?int,
     *     kioskDefault: int, recordingAllowed: bool, recorded: bool, frames: int, retention: int, interval: int}
     */
    public function load(int $mapId): array
    {
        $kiosk = $this->kiosk->getMapSettings([$mapId])[$mapId];
        $recorded = $this->recording->isRecorded($mapId);
        return [
            'timezone' => (string) ($this->timezones->getOverrides([$mapId])[$mapId] ?? ''),
            'defaultZone' => $this->timezones->getDefault(),
            'kioskZoom' => $kiosk['zoom'],
            'kioskDuration' => $kiosk['duration'],
            'kioskDefault' => $this->kiosk->getDefaultDuration(),
            'recordingAllowed' => $this->recording->isAllowed(),
            'recorded' => $recorded,
            'frames' => $recorded ? count($this->recording->frames($mapId)) : 0,
            'retention' => $this->recording->getRetention(),
            'interval' => $this->recording->getInterval(),
        ];
    }

    /**
     * Time zone ('' for the default one) and kiosk zoom and display time
     * (null for the default one).
     *
     * @throws \InvalidArgumentException on a time zone PHP does not know
     */
    public function saveDisplay(int $mapId, string $zone, int $zoom, ?int $duration): void
    {
        $zone = trim($zone);
        if ($zone !== '' && !TimezoneService::isValid($zone)) {
            throw new \InvalidArgumentException($zone);
        }
        $this->timezones->setOverride($mapId, $zone);
        $this->kiosk->setMapSettings($mapId, $zoom, $duration);
    }

    /**
     * Record the map or not. Only while the administrator allows recording;
     * switching it off drops the frames kept. Returns whether it changed.
     */
    public function saveRecording(int $mapId, bool $on): bool
    {
        if (!$this->recording->isAllowed() || $this->recording->isRecorded($mapId) === $on) {
            return false;
        }
        $this->recording->setRecorded($mapId, $on);
        return true;
    }

    /** Starts the recorder when a map was switched on (it stops by itself) */
    public function ensureRecorder(): void
    {
        $this->recording->ensureRecorder();
    }

    /**
     * Who sees the map, read from the existing permissions:
     *  - open: sign-in is off, everybody sees every map;
     *  - staff: administrators and editors, who see every map;
     *  - public: the map is shared with everybody signed in;
     *  - group: viewers of the map's group; direct: viewers given the map itself.
     * Viewers never see an inactive map.
     *
     * @return array{open: bool, active: bool, public: bool, staff: string[], group: string[], direct: string[]}
     */
    public function audience(array $map): array
    {
        $mapId = (int) $map['id'];
        $names = static fn (array $rows): array => array_map(static fn ($r) => (string) $r['username'], $rows);

        return [
            'open' => !$this->config->isAuthEnabled(),
            'active' => (bool) $map['active'],
            'public' => $this->db->queryOne('SELECT 1 FROM user_map_permissions WHERE user_id = 0 AND map_id = ?', [$mapId]) !== null,
            'staff' => $names($this->db->query(
                "SELECT username FROM users WHERE active = 1 AND role IN ('admin', 'editor') ORDER BY username"
            )),
            'group' => $names($this->db->query(
                "SELECT u.username FROM users u JOIN user_group_permissions p ON p.user_id = u.id
                 WHERE p.group_id = ? AND u.active = 1 AND u.role NOT IN ('admin', 'editor') ORDER BY u.username",
                [(int) $map['group_id']]
            )),
            'direct' => $names($this->db->query(
                "SELECT u.username FROM users u JOIN user_map_permissions p ON p.user_id = u.id
                 WHERE p.map_id = ? AND u.active = 1 AND u.role NOT IN ('admin', 'editor') ORDER BY u.username",
                [$mapId]
            )),
        ];
    }
}
