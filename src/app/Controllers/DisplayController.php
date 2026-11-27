<?php
/**
 * Display links (F81): /display/<token> shows one map full screen in kiosk
 * mode, without signing in, for a wall screen or a frame in another tool.
 *
 * The token (DisplayLinks) opens its own map and nothing else: the page,
 * and the routes the page reads under /display/<token>/ (svg, status,
 * totals, version, fonts, link traffic and graphs). Every other route keeps
 * asking to sign in. The addresses the map's answers name under
 * /map/<id>/ are rewritten to the display's.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorPage;
use App\Services\DisplayLinks;
use App\Services\KioskService;

final class DisplayController extends MapController
{
    /** The map the token opens, 0 before it is checked */
    private int $displayMap = 0;
    private string $token = '';

    protected function canView(int $mapId): bool
    {
        return $this->displayMap > 0 && $mapId === $this->displayMap;
    }

    /** GET /display/<token>: the map in kiosk mode; ?theme=light|dark */
    public function page(array $params): void
    {
        if (!$this->open($params, true)) {
            return;
        }
        $map = (array) $this->getMap($this->displayMap);
        $zoom = (new KioskService($this->database))->getMapSettings([$this->displayMap])[$this->displayMap]['zoom'] ?? KioskService::ZOOM_FIT;
        $theme = (string) ($_GET['theme'] ?? '');
        header('Cache-Control: no-store');
        // the token must not leak to the sites the map links to
        header('Referrer-Policy: no-referrer');
        $this->render('maps/view', [
            'map' => $map,
            'timezone' => $this->timezones()->forMap($this->displayMap),
            'fullscreen' => true,
            'kiosk' => true,
            'display' => DisplayLinks::path($this->token),
            'displayTheme' => in_array($theme, ['light', 'dark'], true) ? $theme : '',
            'zoom' => (int) $zoom,
            'autoRefresh' => 0,
            'history' => false,
            'totalsSource' => $this->totalsSource($this->displayMap),
            'historyHours' => 0,
            'title' => $map['title_cache'] ?: $map['name'],
        ], false);
    }

    public function svg(array $params): void
    {
        if ($this->open($params, false)) {
            $this->rewritten(fn () => parent::svg(['id' => $this->displayMap]));
        }
    }

    public function status(array $params): void
    {
        if ($this->open($params, false)) {
            parent::status(['id' => $this->displayMap]);
        }
    }

    public function totals(array $params): void
    {
        if ($this->open($params, false)) {
            parent::totals(['id' => $this->displayMap]);
        }
    }

    public function version(array $params): void
    {
        if ($this->open($params, false)) {
            parent::version(['id' => $this->displayMap]);
        }
    }

    public function font(array $params): void
    {
        if ($this->open($params, false)) {
            parent::font(['id' => $this->displayMap, 'n' => $params['n'] ?? 0]);
        }
    }

    public function linkTraffic(array $params): void
    {
        if ($this->open($params, false)) {
            parent::linkTraffic(['id' => $this->displayMap, 'linkId' => $params['linkId'] ?? 0]);
        }
    }

    public function linkGraph(array $params): void
    {
        if ($this->open($params, false)) {
            parent::linkGraph(['id' => $this->displayMap, 'linkId' => $params['linkId'] ?? 0]);
        }
    }

    /**
     * Checks the token of the address; on failure sends the answer (a page
     * or JSON) and returns false
     */
    private function open(array $params, bool $page): bool
    {
        $this->token = (string) ($params['token'] ?? '');
        [$state, $mapId] = (new DisplayLinks($this->database))->resolve($this->token);
        if ($state === DisplayLinks::LIMITED) {
            header('Retry-After: 60');
            $this->fail($page, 429, __('Too many requests'), __('This display link is used too often. It works again in a few minutes.'));
            return false;
        }
        if ($state !== DisplayLinks::OK || $this->getMap($mapId) === null) {
            $this->fail($page, 404, __('Display link not found'), __('This display link does not exist, was revoked or has expired. Ask for a new one.'));
            return false;
        }
        $this->displayMap = $mapId;
        return true;
    }

    private function fail(bool $page, int $status, string $title, string $text): void
    {
        if ($page) {
            ErrorPage::send($status, $this->auth, $title, $text);
            return;
        }
        $this->json(['error' => $title], $status);
    }

    /** Runs $send and rewrites the map's own addresses in its answer to the display's */
    private function rewritten(callable $send): void
    {
        ob_start();
        try {
            $send();
        } finally {
            $body = (string) ob_get_clean();
        }
        $from = '/map/' . $this->displayMap . '/';
        $to = DisplayLinks::path($this->token) . '/';
        echo str_replace([$from, str_replace('/', '\\/', $from)], [$to, str_replace('/', '\\/', $to)], $body);
    }
}
