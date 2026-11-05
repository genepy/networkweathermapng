<?php
/**
 * NetworkWeathermapNG - side navigation of the Settings space (UI redesign
 * step 6): the administration pages in four groups, with a counter on the
 * pages that list things.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SettingsNav
{
    /**
     * Groups of pages, translated:
     * [group label, [[key, label, address, icon], ...]].
     */
    public static function groups(): array
    {
        return [
            [__('Monitoring'), [
                ['data-sources', __('Data sources'), '/admin/data-sources', 'plug'],
                ['migration', __('Import maps'), '/admin/migration', 'package'],
            ]],
            [__('Display'), [
                ['rendering', __('Maps & rendering'), '/admin/settings', 'map'],
                ['kiosk', __('Kiosk'), '/admin/kiosk', 'tv'],
                ['recording', __('Recording'), '/admin/recording', 'circle-dot'],
                ['timezone', __('Time zone'), '/admin/timezone', 'clock'],
            ]],
            [__('Access'), [
                ['users', __('Users'), '/admin/users', 'users'],
                ['groups', __('Groups'), '/admin/groups', 'folder'],
            ]],
            [__('System'), [
                ['check-db', __('Database check'), '/admin/check-db', 'shield-check'],
            ]],
        ];
    }

    /** The page Settings opens on */
    public const HOME = '/admin/data-sources';

    /** Counters shown next to a page: data sources, users, groups */
    public static function counts(Database $db): array
    {
        $count = static function (string $table) use ($db): int {
            try {
                return (int) ($db->queryOne('SELECT COUNT(*) AS n FROM ' . $table)['n'] ?? 0);
            } catch (\Throwable) {
                // a damaged database must still let the admin reach Database check
                return 0;
            }
        };

        return [
            'data-sources' => $count('data_sources'),
            'users' => $count('users'),
            'groups' => $count('map_groups'),
        ];
    }

    /** The key of the page an address belongs to, or null */
    public static function pageFor(string $path): ?string
    {
        foreach (self::groups() as [, $items]) {
            foreach ($items as [$key, , $href]) {
                if ($path === $href || str_starts_with($path, $href . '/')) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * "tested 2 min ago" for the last check of a data source, from the
     * stored date (server time) and now.
     */
    public static function testedAgo(?string $lastCheck, ?int $now = null): string
    {
        if ($lastCheck === null || $lastCheck === '' || ($time = strtotime($lastCheck)) === false) {
            return __('never tested');
        }
        $seconds = max(0, ($now ?? time()) - $time);
        if ($seconds < 60) {
            return __('tested just now');
        }
        if ($seconds < 3600) {
            return __('tested :n min ago', ['n' => intdiv($seconds, 60)]);
        }
        if ($seconds < 86400) {
            return __('tested :n h ago', ['n' => intdiv($seconds, 3600)]);
        }
        return __('tested on :date', ['date' => date('Y-m-d', $time)]);
    }
}
