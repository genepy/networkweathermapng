# Roadmap

Backlog and status board shared by every agent. Read
[AGENTS.md](../AGENTS.md) first: it explains how to claim an item, keep its
line up to date and hand it over.

States: *Not started*, *In progress*, *Paused*, *Done*. One line per item:
an item with a branch belongs to whoever owns that branch.

Last updated: 2026-10-27.

## In progress

| Item | Branch |
|------|--------|
| Update PHP for CVE-2026-109618 and CVE-2026-109619 (PHP 8.5.12 in the image and CI, Alpine 3.23 with PHP 8.3.36 for the Observium testbed) | `claude/project-thread-8kity5` |

## Paused

These branches are free to take only when the maintainer says so. Each one
is one `WIP:` commit on top of an older `main`, so rebase it first. None has
a pull request yet. The details below come from the agent that wrote each
branch.

| Item | Branch | Based on |
|------|--------|----------|
| [SVG map furniture drawn as vectors](#svg-map-furniture-drawn-as-vectors) | `claude/project-thread-znx5hy` | #22 |
| [Link data source picker](#link-data-source-picker) | `claude/project-thread-6ggwxe` | #22 |
| [Maps pages and editor role](#maps-pages-and-editor-role) | `claude/project-thread-x46foc` | #22 |
| [Kiosk mode settings](#kiosk-mode-settings) | `claude/project-thread-shzu2l` | #22 |

Common gotcha: `composer install` with dev dependencies may fail on GitHub
authentication in a sandbox (`--no-dev` works), so phpunit and phpstan have
not run on these branches. Run them before opening the PR.

### SVG map furniture drawn as vectors

Branch `claude/project-thread-znx5hy`, commit 480065c. Not confirmed by its agent yet: written from the commit.

**Done:** the title, legends and timestamps of the SVG render are each their
own group (`data-kind` legend, title, timestamp, widthlegend) made of rects,
lines and text, instead of one transparent bitmap over the whole map. Legend
boxes drawn on a scratch image are recorded at their `KEYPOS` through
`WeatherMapSvg::adopt()`. Files: `WeatherMap.class.php`,
`WeatherMap.functions.php`, `WeatherMapSvg.class.php`, `compat.php`,
`tests/Engine/SvgRenderTest.php`.

**Left:** rebase on `main` (one PR behind), run phpunit, check the
result in the SVG editor and the viewer, open the PR.

### Link data source picker

Branch `claude/project-thread-6ggwxe`, commit a4deef9.

**Done:** the three dropdowns of the link inspector are replaced by search
boxes: devices by name, interfaces by name or number. The data source is
asked for only when several exist, and a picked interface shows as a
summary with a Change button. Files: `editor-svg.js` (`interfacePicker()`
rewritten, new helpers `dsCache`, `dsFetch`, `dsSources`, `dsHosts`,
`dsInterfaces`, `ifaceNumber`, `parseSelection`, `searchList`),
`editor-svg.css` (`.ds-*` rules, unused `#inspector details/summary` rules
removed), `Lang/fr/editor-js.php`. No server change: it reuses the existing
`/api/data-sources` endpoints.

**Decisions:** filtering is client side, hosts are fetched once per source
and cached per page. The interface number is `ifIndex`, or else a numeric
`interfaceId` when the link has no target. Results are capped at 50. The
last-used source is preselected. The hint format is unchanged: the summary
resolves names client side, so no names (and no spaces) go into the `SET`
hint. The heading is "Monitored interface".

**Left:** rebase on `main` (merges cleanly after #23), run phpunit and
i18n-check, check in a browser in light and dark themes with one data source
and with several (stub `/api/data-sources*` if no Zabbix or Observium is
available), open the PR.

**Gotchas:** `renderInspector()` does not rebuild while an input has focus,
which is why results are buttons. The picker's key handlers call
`stopPropagation` so they do not reach the global editor shortcuts. The
legacy editor (`visual.php`) is not changed.

### Maps pages and editor role

Branch `claude/project-thread-x46foc`, commit 2657f7a.

**Done:**
- Editor role: `Auth::ROLES` (viewer, editor, admin), `Auth::canEdit()`,
  `BaseController::requireEditor()`. Editors see and edit every map, with no
  access to users, groups, data sources, settings or migration. The legacy
  and SVG editors, `MapController` and `ApiController` use `canEdit`. The
  role select of `user_form` has a help text.
- Map management moved from Admin to the Editor tab, in a new
  `MapManagementController` (taken out of `AdminController`). Views:
  `editor/index.php` (`admin/maps.php` deleted) and `editor/map_form.php`
  (moved from `admin/`). Each row has Edit, View and Preview with icons
  (labels hidden under 900px), a "more" menu (Properties, Duplicate, .conf,
  Delete) and an active switch (`POST /editor/maps/toggle/{id}`).
- Routes are `/editor/maps/*`. `/admin/maps/*` are kept as aliases, and
  `GET /admin/maps` redirects to `/editor`.
- Map forms take `?return=` (local paths only, `safeReturn`), so Cancel and
  Save go back to where the user came from.
- A `breadcrumb()` helper (`Core/helpers.php`) gives every page the same
  header, with the last crumb as the h1.
- Dark mode: the admin counters, active tabs and breadcrumb hover use
  `--link-color`.
- French strings added, `i18n-check fr` shows 0 missing.

**Left:** run phpunit (`AuthRolesTest` has never run) and phpstan, fix what
breaks, check in a browser as admin, editor and viewer in light and dark
themes, screenshots, open the PR.

**Gotchas:** the `.data-table-container` overflow is overridden on
`.maps-admin-page` so the menu is not clipped. Delete always returns to
`/editor`. Import and the Migration hint show for admins only. Expect
conflicts in `AdminController` and the admin views with the kiosk branch
when rebasing.

### Kiosk mode settings

Branch `claude/project-thread-shzu2l`, commit 67e4ab0.

**Done:** a global display time (default 30 s, 5 to 86400 s), and per map a
zoom (Fit to screen, or 50 to 300 %) plus an optional display time that
overrides the global one. They are stored in the existing `settings` table
(`map_id` 0 holds `kiosk_duration`, `map_id` N holds `kiosk_zoom` and
`kiosk_duration`): no schema change, and deleting a map already purges its
rows. New page Admin > Kiosk mode at `/admin/kiosk` (admin only) with a
dashboard tile. `/cycle` gives each map its own `setTimeout` duration.
"Fit" scales the map up or down; a percentage applies to the natural size,
centred and cropped. `/cycle?group=N` now works as a query parameter, which
fixes the fullscreen link. Admins get a "Kiosk settings" button on the cycle
page. French strings added, `i18n-check fr` shows 0 missing.

Files: new `Services/KioskService.php`, `Views/admin/kiosk.php`,
`tests/App/KioskServiceTest.php`; changed `AdminController::kiosk`,
`MapController::cycle`, `Core/Application.php` (routes),
`Views/admin/index.php`, `Views/maps/cycle.php`, `weathermap.css`
(`.cycle-*`), `Lang/fr/admin.php` and `public.php`.

**Decision:** the default changed from shrink-only to fit to screen, which
also enlarges small maps.

**Left:** rebase on `main`, run phpunit (`KioskServiceTest` only passed as a
standalone script) and phpstan, check `/admin/kiosk` and `/cycle` in a
browser (fullscreen and normal, fit and fixed zoom, light and dark themes),
open the PR.

**Gotchas:** a fresh database forces a password change for admin/admin, so
set `ADMIN_PASSWORD`. With `php -S`, do not pass `index.php` as the router,
or static assets return 404 and POSTs return 419. If "Maps pages and editor
role" merges first, keep `kiosk()` admin only and check where the admin tile
goes.

## Not started

| Item | Notes |
|------|-------|
| Map import fixes | Fix the remaining differences when importing php-weathermap maps (`src/app/Services/Migration/`, `tests/fixtures/migration/`). Ask the maintainer for the failing maps |
| SVG editor polish | Smaller editing improvements after #23. Ask the maintainer for the current list |
| Cacti plugin study | Study what it would take to run as, or import from, the Cacti Weathermap plugin. A written study first, no code |

## Stale branches

| Branch | Note |
|--------|------|
| `claude/project-thread-9dr755` | "Build the testbed images with the classic Compose builder": superseded by #20 (host-network entitlement). Can be deleted |

## Recently done

| PR | Item |
|----|------|
| #23 | Improve canvas interactions in the SVG editor |
| #22 | Show the traffic graph of a link and open its monitoring page |
| #21 | Test and build on the latest stable PHP only (8.5) |
| #20 | Grant the host-network entitlement when building the testbed |
| #18 | Add a workflow to bulk-delete old workflow runs |
| #17 | Build the testbed images on the host network |
