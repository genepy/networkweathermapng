# Roadmap

Backlog and status board shared by every agent. Read
[AGENTS.md](../AGENTS.md) first: it explains how to claim an item, keep its
line up to date and hand it over.

States: *Not started*, *In progress*, *Paused*, *Done*. One line per item:
an item with a branch belongs to whoever owns that branch.

Last updated: 2026-10-27.

## In progress

Nothing is in progress right now.

## Paused

These branches are free to take only when the maintainer says so. Each one
is one `WIP:` commit on top of an older `main`, so rebase it first.

| Item | Branch | Done | Left |
|------|--------|------|------|
| SVG map furniture drawn as vectors | `claude/project-thread-znx5hy` | Title, legends and timestamps of the SVG render are their own groups (`data-kind` legend, title, timestamp, widthlegend) made of rects, lines and text instead of one bitmap; legend boxes recorded at their `KEYPOS` through `WeatherMapSvg::adopt()`; `tests/Engine/SvgRenderTest.php` extended | Rebase on `main`, run phpunit, check the result in the SVG editor and the viewer, open the PR |
| Link data source picker | `claude/project-thread-6ggwxe` | The three dropdowns of the link inspector are replaced by search boxes (devices by name, interfaces by name or number); the data source is asked for only when several exist; a picked interface shows as a summary with a Change button; French strings in `editor-js.php` | Rebase on `main` (the editor changed in #22 and #23), browser check in light and dark themes, open the PR |
| Maps pages and editor role | `claude/project-thread-x46foc` | New *editor* role (`Auth`, `tests/App/AuthRolesTest.php`); map management moved from Admin to the Editor tab (`MapManagementController`, `Views/editor/`); shared page headers across admin views | Run phpunit and phpstan, fix what breaks, browser check with each role, screenshots, open the PR |
| Kiosk mode settings | `claude/project-thread-shzu2l` | Global display time for all maps, and per map a zoom and an optional display time, set in Admin > Kiosk mode (`KioskService`, `Views/admin/kiosk.php`, `maps/cycle.php`, `tests/App/KioskServiceTest.php`) | Browser check of the kiosk pages, open the PR |

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
