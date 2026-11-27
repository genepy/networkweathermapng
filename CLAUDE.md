# Instructions for Claude

Read [AGENTS.md](AGENTS.md) first: project rules, architecture table,
dev/test/deploy notes and the issue workflow. It applies to Claude too. This
file adds the facts a new Claude thread needs so it does not have to
re-explore the repository. Keep it short and up to date.

## Where things are

- Entry point: `src/public/index.php` → `src/app/Core/Application.php`
  (all routes are declared there, e.g. `/map/{id}`, `/map/{id}/image`,
  `/admin/kiosk`). Settings come from `App\Core\Config`; read an
  environment variable with `Config::env()` (real variables, then `.env`),
  not `getenv()`.
- New map template: `MapService::generateDefaultConfig()`. Map items
  (title, legends, cards) in the editor: `moveFurniture()` /
  `setFurnitureShown()` in `MapEditor`, `renderMapItems()` in
  `editor-svg.js`; a negative position (`TITLEPOS -1 -1`) hides an item.
  Older legends (`KEYPOS`, `SET width_legend`) become `SET box_<id>` cards
  when the editor opens a map: `MapEditor::convertLegacy()`, history kind
  `convert`. The engine no longer draws `TIMEPOS`: the status bar takes its
  place (`MapService::statusBar()` fills `$map->status_bar`, drawn by
  `WeatherMapBoxes::drawStatus()`; the viewer moves its live HTML bar over
  it, furniture kind `status` in the editor).
- Map engine (php-weathermap fork): `src/lib/WeatherMap/`.
  `WeatherMap.class.php` (config parser, PNG render), `WeatherMapSvg.class.php`
  (SVG render), `WeatherMapNode/Link/Zone/Boxes.class.php`,
  `WeatherMap.functions.php` (helpers, colours, fonts), `geo.php`.
- Viewer: `MapController` + `src/app/Views/maps/view.php`,
  `src/public/assets/js/viewer.js`, `geo-view.js`, `basemap.js`.
- Editor (SVG): `SvgEditorController` + `src/app/Views/editor/svg.php`,
  `src/app/Services/Editor/MapEditor.php` (config edits),
  `ConfigHistory.php` (savepoints), `src/public/assets/js/editor-*.js`.
  The Map panel (`Views/editor/map_settings.php`, tabs, `map-settings.js`) is also the
  editor's properties panel with nothing selected (`mapPanel()` fills its
  `data-ms-slot` boxes). Its live sections live in `editor-mapprops.js`; `ed.openMapProperties('<id>')`
  or `/editor/edit/<id>?open=<section>` opens one (e.g. `basemap`).
- Kiosk / cycle: `KioskService`, `Views/maps/cycle.php`, `assets/js/kiosk.js`,
  admin page `Views/admin/kiosk.php`.
- Maps page: `MapManagementController`, `Views/maps/index.php`, `maplist.js`.
- Demo maps: `src/examples/maps/*.conf`, installed by `Services/DemoMaps.php`
  (`bin/demo-maps.php`, or `POST /editor/maps/demos` from the Maps page),
  plus `DEMO_MAPS_EXTRA_DIR` (the testbed map, `deploy/testbed/weathermap/demo/`).
  The datacenter cooling and power demos use the icons in `objects/animated/` (CSS keyframes) and
  `objects/datacenter/`. An animated icon's still PNG twin rides along as
  `data-still` in the SVG, swapped in by `stillIcons()` in `viewer.js` for
  reduced motion. PNG twins of new SVG icons: render them at 64 px (e.g.
  Playwright with `reducedMotion: 'reduce'`), then `php src/bin/icons-manifest.php`.
- Data sources: `Services/Observium/`, `ZabbixService.php`, `src/lib/Zabbix/`.
- Total traffic graph (side panel, total box, kiosk): `Services/TotalTrafficSeries.php`
  decides its source (recorded frames, demo day for static data, or none),
  served by `/map/{id}/totals` (`?period=day|week|month&group=<name>`, or
  `?format=series` for the zoomed graph). Link groups: `SET traffic_group`;
  per-frame totals are cached in `recordings/<id>/totals.json`.

## Map config hints (`SET <name> <value>` in a `.conf`)

App features store their settings as map hints, each parsed by one service:

| Hint | Service |
|------|---------|
| `viewer_panels` (e.g. `sites:side total:top-right updated:bottom-left:hidden`; the status bar can sit on the map: `updated:map:<x>,<y>`), `kiosk_panels` (same syntax, or `same`) | `Services/ViewerPanels.php` |
| `focus_<id>`, `focus_default` | `Services/FocusAreas.php` |
| `basemap`, `basemap_dark`, `basemap_center`, `basemap_zoom`, `basemap_attribution` | `Services/Basemap.php` |
| `kiosk_duration`, `kiosk_zoom` | `Services/KioskService.php` |
| `link_look`, `link_bar_separator`, `link_bar_outline` (map or link) | `WeatherMapLink::look()` / `bar_colour()`, `MapEditor::setBarHint()` |
| `icon_spin` (node) | `MapService::spinsWithTraffic()`, `spinIcons()` in `viewer.js` |
| `traffic_group`, `traffic_total` (link) | `Services/TotalTrafficSeries.php`, `MapService::trafficTotal()` |
| `traffic_total_source`, `traffic_group_source_<group>` (map) | `Services/TrafficTotalSource.php`, `MapService::sourceTotals()`, `MapController::totalRows()` |
| `box_<id>` (and `_title`, `_style`, `_text`, `_rows`, `_width`, `_note`, `_scale`, `_hidden`) | `WeatherMapBoxes`, `MapEditor::setBox()` |
| `link_glow`, `node_style`, `link_flow`, `traffic_*`, `cluster*`, `geo`, `site`, `box_*`, `editor_note*` | grep `SET <name>` in `src/app/Services` and `src/lib/WeatherMap` |

The README documents the user-facing config format.

## Translations

- Catalogues: `src/app/Lang/<code>/*.php` (English text is the key, no `en`
  folder). `fr` is the reference language; see [TRANSLATING.md](TRANSLATING.md)
  to add a language (copy the folder, edit `_meta.php`).
- PHP: `__('Text :name', ['name' => $x])`, `e__()` to echo escaped.
- JS: strings live in the `*-js.php` files; views inject a section into
  `window.WM_T` (`I18n::section('ui-js')` in `Views/layouts/main.php`,
  `editor-js` in `Views/editor/svg.php`) and scripts call `t('Text')`.
- Every new or changed string gets its French entry in the same change.
  Check: `php src/bin/i18n-check.php fr` (CI runs `--strict`, informative).

## Checks

CI (`.github/workflows/ci.yml`, PHP 8.5.12+) runs on every PR:
`php -l` on `src` and `tests`, `vendor/bin/phpstan analyse`,
`vendor/bin/phpunit`, then the informative translation check. Rendered maps
are uploaded as an artifact when a test fails.

Locally: `composer install`, then the same commands (see AGENTS.md).
Render tests compare `tests/fixtures/maps/` to `tests/fixtures/reference/`;
regenerate with `UPDATE_REFERENCE=1 vendor/bin/phpunit` after an intended
visual change.

## Environment limits in Claude threads

- The thread container usually has an older PHP (8.3) and `composer install`
  may fail (PHP 8.5 required, network). Do not spend time on it: run
  `php -l` and `php src/bin/i18n-check.php fr` locally, and rely on CI for
  phpstan and phpunit.
- Do not try browser tests when no browser or running app is available. List
  instead what the maintainer should check on the testbed (light and dark
  themes, phone width).

## Docker and testbed gotchas

- Production image: `docker/` (nginx + PHP-FPM + supervisor). Testbed:
  `deploy/testbed/` (Observium, MariaDB, rrdcached, snmpsim), deployed to a
  Raspberry Pi on every merge to `main`.
- GD needs absolute TrueType font paths: fonts live in `src/public/fonts/`,
  and code that hands a font to GD resolves it with `realpath()` (see
  `MapService` font lookup). Relative paths break when PHP-FPM runs from
  another working directory.
- GD bitmap fonts (1-5) cannot rotate; curved or rotated text needs a
  `FONTDEFINE` TrueType font.

## Workflow for a Claude thread

1. One thread works through the whole todo in order (lowest task number
   first, skipping issues marked on hold). Issues are the todo: each one
   names the files concerned and the expected result.
2. Group 2 or 3 small or related tasks in one PR. For each PR: branch,
   push, open a draft PR with `Closes #<issue>` for each task.
3. Implement, keep the PR description current, mark it ready.
4. Wait for green CI, review your own diff, merge it yourself, check the
   issues are closed, delete the branch. No separate review thread. Post a
   short update to the maintainer after each merge, then take the next task.
5. The thread auto-compacts its context. If it stalls or grows too large,
   it is closed and a new thread resumes at the next open task.
6. Only one thread works at a time.

Conversations with the maintainer are in French; code, commits, PRs, issues
and docs are in English.

## graphify

When `graphify-out/graph.json` exists, run `graphify query "<question>"`
before the first grep or source read for a codebase question (and put this
rule in every subagent prompt that explores code). Use `graphify path "<A>"
"<B>"` for relationships and `graphify explain "<concept>"` for a concept.
Use `graphify-out/wiki/index.md` for navigation when present, and
`graphify-out/GRAPH_REPORT.md` only for broad architecture review. After
changing code, run `graphify update .`. A large diff of
`graphify-out/graph.json` in a PR is expected; do not flag it.
