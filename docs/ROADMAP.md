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
| [Link data source picker](#link-data-source-picker) | `claude/project-thread-6ggwxe` | #22 |

Common gotcha: `composer install` with dev dependencies may fail on GitHub
authentication in a sandbox (`--no-dev` works), so phpunit and phpstan have
not run on these branches. Run them before opening the PR. The cause is
`phpstan/phpstan`, which only ships as a GitHub zip: install the rest with
`--prefer-source` from a copy of `composer.json`/`composer.lock` without
phpstan (`COMPOSER=composer.tmp.json`), and run `phpstan.phar` from a
`git clone` of `phpstan/phpstan`.

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

## Recently done

| PR | Item |
|----|------|
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
