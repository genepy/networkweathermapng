# Roadmap

Backlog and status board shared by every agent. Read
[AGENTS.md](../AGENTS.md) first: it explains how to claim an item, keep its
line up to date and hand it over.

States: *Not started*, *In progress*, *Paused*, *Done*. One line per item:
an item with a branch belongs to whoever owns that branch.

Last updated: 2026-10-30.

## In progress

| Item | Branch | Notes |
|------|--------|-------|
| (none) | | |

## Paused

These branches are free to take only when the maintainer says so. Each one
is one `WIP:` commit on top of an older `main`, so rebase it first. None has
a pull request yet. The details below come from the agent that wrote each
branch.

| Item | Branch | Based on |
|------|--------|----------|
| (none) | | |

Common gotcha: `composer install` with dev dependencies may fail on GitHub
authentication in a sandbox (`--no-dev` works), so phpunit and phpstan have
not run on these branches. Run them before opening the PR. The cause is
`phpstan/phpstan`, which only ships as a GitHub zip: install the rest with
`--prefer-source` from a copy of `composer.json`/`composer.lock` without
phpstan (`COMPOSER=composer.tmp.json`), and run `phpstan.phar` from a
`git clone` of `phpstan/phpstan`.

## Not started

| Item | Notes |
|------|-------|
| Time zone in the remaining previews | The old PNG editor previews (`EditorController`) and the import compare view (`MapMigrator`) still draw the timestamp in the server's zone. Wrap their draws in `TimezoneService::using()` as #31 does elsewhere |
| Import PHP Weathermap 2.0 maps | Read `COMMENTCURVE` and the hints `editor_note`, `editor_note_align`, `editor_note_escaped`, `traffic_pulse`, `background_sizing` and `editor_label_position` without warnings. See [the study](studies/php-weathermap-2.0.md) item 1 |
| Map notes | Free-text blocks on the map, several lines, aligned, drawn sharp in SVG and PNG. Idea from PHP Weathermap 2.0, see [the study](studies/php-weathermap-2.0.md) item 2 |
| Colour scale editor with presets | Edit the DEFAULT scale in the SVG editor, with our own palettes readable in light and dark themes and safe for colour-blind people. Study item 3 |
| Highlight saturated links | Pulse or glow on links at 80 % or more in the viewer, per-map switch, off under `prefers-reduced-motion`. Study item 4 |
| Comments along curved links | IN/OUT comments on a text path following the curve, straight fallback. Study item 5 |
| Background library | Upload, delete and search backgrounds from the editor, as #25 does for icons. Study item 6 |
| Background fit or stretch | Map setting: natural size, fit or stretch. Study item 7 |
| Node label position presets | Top, centre or bottom of the icon in one click. Study item 8 |
| Refuse undo after an outside change | Do not overwrite a map saved elsewhere since the step was recorded (`ConfigHistory`). Study item 9 |
| Cacti plugin study | Study what it would take to run as, or import from, the Cacti Weathermap plugin. A written study first, no code |
| Editor: properties panel stuck on one object | Bug: at times the edit panel keeps showing the same object and no longer follows the selection when another object is clicked. Find the steps to reproduce first |
| Editor: fields apply on blur and on Enter | Clicking outside a field leaves it and applies the change; Enter in a field validates and applies the pending change |
| Editor: resize icons | Resize a node's icon from the editor (handles and a size field) |
| Editor: search in the icon library | A search field in the icon picker (name, set, theme) |
| Editor: link widths up to 800G | Allow link bandwidths up to 800G in the editor fields and the parser, and check the scaling of widths and labels |
| Editor: zone resize per side and guides | Resize a zone from one side only (horizontal or vertical) as well as both at once, and show the alignment guides while resizing |
| Editor: right-angle routing per link | Route one link with right angles (orthogonal via points) without applying it to the whole map |
| Editor: clearer layers note | The Layers panel note "Only in this editor, in this browser." is unclear: say that hiding a layer only changes this editor view, is remembered in this browser and is not saved in the map |
| Editor: custom layers | User-defined layers: name a group of nodes, or a set of nodes and links, then show, hide or lock it as a whole |
| Modern legend styles | Several styles for the legends and labels (traffic load scale, "Created:" timestamp…) with a more modern look, chosen per map |
| More open-source fonts | Bundle a few modern open-source fonts (licences in THIRD-PARTY-NOTICES.md) usable in SVG and PNG output |
| Map recording and playback | Record a map's states over a period (for example the last 24 hours) and play them back as an animation. Off by default, switched on per map, and the admin can disable the feature to protect the server (storage and polling load) |

## Stale branches

| Branch | Note |
|--------|------|
| `claude/project-thread-9dr755` | "Build the testbed images with the classic Compose builder": superseded by #20 (host-network entitlement). Can be deleted |

## Recently done

| PR | Item |
|----|------|
| #41 | Editor: the Zone button shows the new zone under the pointer, a click drops it, Esc cancels |
| #32 | Search devices and interfaces (name or number) when picking a link's data source |
| #33 | Editor role, one maps page in the Editor tab (action buttons, active switch), forms go back where they came from, shared page headers, dark theme counters |
| #26 | Polish link and object editing in the SVG editor: Ctrl+drag duplicates, align keeps groups, link labels over other links, larger traffic graph |
| #31 | Set the time zone of map dates: a default for all maps and an override per map (Admin > Time zone) |
| #30 | Study of PHP Network Weathermap 2.0 ([docs/studies/php-weathermap-2.0.md](studies/php-weathermap-2.0.md)) |
| #25 | SVG editor interface and icons: movable toolbars, icon library with themes and the Affinity and Ansible sets, Map properties, vector legends |
| #29 | Configure kiosk mode per map: zoom and display time |
| #28 | Update PHP for CVE-2026-109618 and CVE-2026-109619 (8.5.12 in the image and CI, Alpine 3.23 for the Observium testbed) |
| #27 | Map import fixes: Latin-1 maps (php-weathermap 0.97 and older), compare view of large maps |
| #23 | Improve canvas interactions in the SVG editor |
| #22 | Show the traffic graph of a link and open its monitoring page |
| #21 | Test and build on the latest stable PHP only (8.5) |
| #20 | Grant the host-network entitlement when building the testbed |
| #18 | Add a workflow to bulk-delete old workflow runs |
| #17 | Build the testbed images on the host network |
