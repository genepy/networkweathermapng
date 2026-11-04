# Study: NetworkWeathermapNG and Cacti

Date: 2026-11-04. A written study only: no application code was written
and none was copied.

Question from the roadmap: what would it take to run NetworkWeathermapNG
**as** a Cacti plugin, to **integrate** it with Cacti, or to **import from**
the Cacti Weathermap plugin?

Sources read:

- Cacti `1.2.x` branch (version 1.2.32): `include/global.php`,
  `include/global_languages.php`, `lib/dsstats.php`.
- The Cacti documentation repository: `Plugin-Creating-Plugins.md`,
  `Plugin-Development.md`, `Plugin-Guidelines.md`,
  `Plugin-Hook-API-Ref.md`, `Requirements.md`.
- [Cacti/plugin_weathermap](https://github.com/Cacti/plugin_weathermap)
  `develop` (version 2.0): `INFO`, `setup.php`, `includes/database.php`,
  `lib/datasources/`, licence headers.
- This repository: `src/app/Core`, `src/app/Services/Migration`,
  `src/app/Services/DataSourceService.php`, `src/lib/WeatherMap`.

## Summary

| Option | What it gives | Effort | Risk | Verdict |
|---|---|---|---|---|
| A. Cacti as a data source of the standalone app | Cacti users keep Cacti, add NG next to it, maps read Cacti data | Medium (3 PRs) | Low | **Do first** |
| B. Better import from the Cacti plugin | Cacti targets and the plugin's groups and titles come over without hand work | Small to medium (2 PRs, needs A) | Low | **Do with A** |
| C. A thin "bridge" plugin for Cacti | A Weathermap NG tab inside Cacti, single sign-on, links between both | Small (1 to 2 PRs) | Low to medium | **Later, if asked** |
| D. Run the whole app inside Cacti as a native plugin | One product inside Cacti, Cacti users and permissions | Very large | High | **Do not do** |

The short version: NetworkWeathermapNG should stay a standalone
application and **talk to** Cacti (options A and B), the way it already
talks to Zabbix and Observium. A small bridge plugin (C) can come later
for people who want a tab in Cacti. A full port (D) costs a rewrite of the
app shell, ties the release cycle to Cacti, collides with the existing
Weathermap plugin and brings little the other options do not.

## How a Cacti plugin works

Facts from the Cacti documentation and source, needed to judge the
options.

- A plugin is a folder `plugins/<name>/` inside the Cacti web root. The
  name is letters and digits only and must match the folder.
- It needs an `INFO` file (ini format: `name`, `version`, `longname`,
  `author`, `homepage`, `compat` = minimum Cacti version, `capabilities`
  for remote pollers, `nosync`) and a `setup.php` holding only functions.
- `setup.php` must define `plugin_<name>_install()`,
  `plugin_<name>_uninstall()`, `plugin_<name>_version()` and
  `plugin_<name>_check_config()`. Cacti 1.3 adds the optional
  `plugin_<name>_has_data()` and `plugin_<name>_remove_data()`.
- The plugin hooks into Cacti with
  `api_plugin_register_hook(plugin, hook, function, file)`: for example
  `top_header_tabs` and `top_graph_header_tabs` (a tab),
  `draw_navigation_text` (breadcrumbs), `config_settings` (a settings tab),
  `poller_bottom` (work after each poll), `page_head`.
- Access control uses realms:
  `api_plugin_register_realm(plugin, 'page.php,other.php', 'Label', 1)`
  adds a checkbox to Cacti's user permissions. A plugin page starts by
  including Cacti's `include/auth.php`, which logs the user in and checks
  the realm.
- Pages are drawn inside Cacti's frame (`top_header()`,
  `bottom_footer()`), with Cacti's themes, jQuery and its AJAX page
  loading. Newer Cacti releases send a Content-Security-Policy that needs
  a per-request nonce on inline scripts (`CactiSecureHeaders`).
- Data lives in Cacti's MySQL/MariaDB database, reached through Cacti's
  `db_*` functions. Plugins create their own tables at install.
- Every hook of every enabled plugin runs **in the same PHP process**: a
  page load or a poller run includes the `setup.php` of all of them.
- The guidelines ask every plugin to isolate its names, with classes or
  with the plugin name as a prefix of its functions.
- Requirements: Cacti 1.2.31 and later need **PHP 8.1** or later and
  Composer. Distributions run Cacti on their own PHP (8.2 on Debian 12,
  8.3 on Ubuntu 24.04, 8.0 to 8.3 on RHEL 9 streams).
- Cacti has no official HTTP API for its data. Other programs read its
  database and its RRD files.

How the Cacti Weathermap plugin uses this: realms *View*, *Manage* and
*Edit Weathermaps*; tables `weathermap_maps`, `weathermap_groups`,
`weathermap_auth`, `weathermap_settings`, `weathermap_data`; maps drawn
by the `poller_bottom` hook; Cacti-only data sources `dsstats:`,
`cactihost:`, `cactithold:` / `cactimonitor:` and `wmdata:`, plus `rrd:`
reading `poller_output` instead of the RRD file when it runs inside Cacti.

## Where NetworkWeathermapNG stands

- It is a standalone PHP application: its own front controller and
  router, its own users and roles (`Auth`), its own sessions, SQLite by
  default (MySQL possible, `DB_TYPE=mysql`), its own background recorder,
  Docker image and testbed.
- Data sources are Zabbix and Observium, behind
  `DataSourceClientInterface` (hosts, interfaces, current and historical
  bandwidth). Observium has three backends: API, MySQL and RRD.
- The map engine in `src/lib/WeatherMap` comes from the Cacti plugin's MIT
  files and **keeps their global names**: classes `WeatherMap`,
  `WeatherMapNode`, `WeatherMapLink`, `WMPoint`, `WMVector`, `WMLine`,
  `HTML_ImageMap`, functions `wm_debug()`, `wm_warn()`,
  `get_allowed_weathermaps()`, `is_weathermap_allowed()`... The same names
  exist in the Cacti plugin.
- `src/lib/WeatherMap/compat.php` stands in for the Cacti functions the
  engine calls (`cacti_log`, `cacti_sizeof`...), each behind
  `function_exists()`.
- The `rrd` data source still has its Cacti branch (`poller_output`,
  `$map->context == 'cacti'`), unused outside Cacti.
- Import (`src/app/Services/Migration`) already reads plugin `.conf` files
  and zips. `TargetParser` tags `cacti*:` and `dsstats:` targets as kind
  `cacti` but nothing can convert them: there is no Cacti source to point
  them at. Cacti RRD file names (`<host>_traffic_in_<id>.rrd`) give a host
  name only, which the matcher tries against Zabbix or Observium.
- UI strings use `__()` and `e__()` from `src/app/Core/helpers.php`, also
  behind `function_exists()`.
- The application code seems to need nothing newer than PHP 8.1 (the
  newest feature found is the `never` return type), but only PHP 8.5 is
  tested in CI and the docs ask for 8.5.12.

## Option A: Cacti as a data source of the standalone app

Add `cacti` next to `zabbix` and `observium` in `DataSourceService`, with
a client that fulfils `DataSourceClientInterface`. Same shape as the
Observium client, which already reads a monitoring system's MySQL and RRD
files.

**Backends**

1. *Database* (read-only MySQL account on Cacti's database): the inventory
   and, when Cacti's Data Source Statistics (DSStats) are on, the current
   values.
   - Hosts: `host` (`id`, `description`, `hostname`, `status`).
   - Interfaces: `host_snmp_cache` rows of the interface query
     (`field_name` `ifName`, `ifAlias`, `ifDescr`, `ifHighSpeed`,
     `ifSpeed`, keyed by `snmp_index`).
   - The data source of an interface: `data_local` (`host_id`,
     `snmp_query_id`, `snmp_index`) → `data_template_data`
     (`data_source_path`, with `<path_rra>`) and `data_template_rrd`
     (`data_source_name` such as `traffic_in`, `traffic_out`).
   - Current rate: `data_source_stats_hourly_last` (`local_data_id`,
     `rrd_name`, `value`). Values are per second, in the unit of the data
     source (bytes for the standard traffic templates: multiply by 8).
   - Device status for node colours: `host.status`.
2. *RRD files* (Cacti's `rra/` folder mounted read-only, `rrdtool` in the
   image, as for Observium RRD): current value and history with
   `rrdtool fetch`, for installs without DSStats.

**Targets.** A new target form, for example
`cacti:<local_data_id>:<ds_in>:<ds_out>` for a link and
`cacti:host:<host_id>` for a node's state. It must not reuse the plugin's
`dsstats:` and `cactihost:` keywords, because imported maps should be
converted on purpose (option B), not run by accident against a source the
user has not set up.

**Editor pickers.** Hosts, then interfaces by name, alias or index (the
search of #32 works as is), bandwidth from `ifHighSpeed`. The traffic
graph of a link (#22) can come from RRD history or from Cacti's own graph
page: `graph.php?local_graph_id=<id>` found through `graph_local`, the way
Observium links open the port page.

**Testbed.** `deploy/testbed` gains a Cacti container (Cacti, MariaDB,
spine) polling the simulated SNMP devices, so the source is checked
against a real Cacti on the Raspberry Pi. Cacti publishes no official
image: build one from the distribution packages (available on ARM64), and
check the memory budget of the Pi.

**Effort.** Three PRs: database backend with pickers and current values;
RRD backend and history; testbed container. Similar to the Observium work.

**Risks.** Cacti's schema moves slowly but is not a public API: pin the
tested Cacti versions (1.2.x, 1.3) and keep the queries in one class.
DSStats is off by default in Cacti: the UI must say when values come
from RRD instead, or when neither is available.

## Option B: better import from the Cacti plugin

Builds on A.

1. **Convert Cacti targets.** When the import's data source is a Cacti
   source: `dsstats:<id>:<in>:<out>` and `dsstats:<type>:<id>:<in>:<out>`
   become `cacti:<id>:<in>:<out>` (the type, `last` or an average, maps to
   current value or RRD), `cactihost:<id>` becomes `cacti:host:<id>`,
   Cacti RRD paths are matched to their `local_data_id` through
   `data_template_data.data_source_path` (exact, not a guess on the host
   name). `cactithold:` / `cactimonitor:` need the Thold and Monitor
   plugins' tables: list them on the review page as not converted, like
   `external:` today. `wmdata:` reads the plugin's own `weathermap_data`
   table: not converted.
2. **Bring the plugin's map list over.** With the same database account,
   read `weathermap_maps` and `weathermap_groups` (file name, title, group,
   sort order, active flag) and offer to import every map at once, with
   their groups, instead of one zip per map. The `.conf` files still come
   from the plugin's `configs/` folder (upload or mounted volume). Map
   permissions (`weathermap_auth`) refer to Cacti users: report them, do
   not import them.

**Effort.** Two PRs. `TargetParser` and `TargetMatcher` already have the
places to plug the rules in.

## Option C: a thin bridge plugin

A small Cacti plugin, named for example `wmng` (it must not be
`weathermap`), that does not contain the app:

- a *Weathermap NG* tab (`top_header_tabs`, `top_graph_header_tabs`) and a
  realm *View Weathermap NG*;
- a page that frames the NG viewer or kiosk (or links to it), using
  Cacti's frame only for the tab;
- a settings tab (`config_settings`): NG address and a shared secret;
- optional single sign-on: the plugin page signs a short-lived token
  (user name, realms, expiry, HMAC with the shared secret) and NG accepts
  it on a dedicated route, creating or updating a local user with the role
  that matches the Cacti realms (viewer, editor, admin). NG refuses frames
  from other sites today (`frame-ancestors 'self'` in `Application.php`,
  `X-Frame-Options: SAMEORIGIN` in the nginx config): it must allow the
  Cacti address instead, only when the bridge is configured;
- optional: a link from a Cacti graph to the NG maps that use its data
  source (`graph_buttons` hook), once option A exists.

**Effort.** One PR for the plugin (its own folder, for example
`integrations/cacti/wmng/`, or a separate repository so it can follow
Cacti's release cycle), one PR in NG for the token login and the frame
setting.

**Risks.** Token login is a new way into the app: replay protection
(expiry of a minute, one-time nonce), clock skew, constant-time HMAC
check, and it must be off unless configured. Framing needs the right
`SameSite` cookie setting when Cacti and NG are on different hosts.
Testing needs a Cacti instance (the testbed of option A).

## Option D: the whole app as a native Cacti plugin

Running NG's pages inside Cacti, on Cacti's users, database and poller.

What it would take:

- **App shell.** Replace the front controller, router, layouts, login,
  roles and CSRF with Cacti pages (`include/auth.php`, `top_header()`,
  realms, Cacti's CSRF). Every controller and view changes. The redesign
  in progress (top bar, user menu, bottom nav, toasts, command palette)
  does not fit inside Cacti's frame and would need a second layout.
- **Database.** Move from SQLite to Cacti's MySQL through `db_*`, with
  plugin tables created at install and upgraded by version.
- **Rendering.** The recorder and map refresh move to `poller_bottom`.
- **Name collisions.** The engine's global classes and functions are the
  same as the Cacti Weathermap plugin's. Both plugins enabled means
  `Cannot declare class WeatherMap` in the poller, where all hooks run in
  one process. The engine would have to move into a namespace first, a
  large change across code derived from the original.
- **Translations.** Cacti defines its own global `__()` (gettext, text
  domains, `sprintf`-style arguments). Ours is skipped when one exists, so
  inside Cacti every NG string would go through Cacti's function with the
  wrong arguments. All helpers would need prefixed names.
- **Browser side.** Cacti's AJAX page loading, jQuery and themes, and its
  CSP nonces, against our standalone editor and viewer scripts.
- **PHP versions.** The plugin would run on the distribution's PHP (8.1 to
  8.3 today), not on 8.5. CI would need 8.1 to 8.5 and every Cacti version
  supported.
- **Two products.** Docker, Zabbix and Observium users still need the
  standalone app, so every feature would be built and tested twice, or the
  code split into a shared core and two shells.

**Effort.** Months of work, larger than everything done since the import
work began. **Risk.** High: a moving host application, collisions with
the official plugin, and a split codebase. It also competes head-on with
the Cacti Group's own Weathermap 2.0 for the same users, where options A
to C complement it.

## Licences

- **Cacti** is GPL-2.0 (or later, per file headers). **The Cacti
  Weathermap plugin** holds an MIT `LICENSE` and a GPL-2 `COPYING`; on
  `develop`, 67 of its 168 PHP files carry a GPL-2 header; most of the
  others carry the MIT header, a few none. NetworkWeathermapNG is MIT and must stay so.
- **Options A and B** only read Cacti's database schema, its RRD files and
  the plugin's `.conf` format and table layout. Those are interfaces and
  file formats, not code: no licence issue. Write the queries from the
  schema, do not copy the plugin's data sources. (The plugin's
  `dsstats`, `cactihost`, `cactithold` and `wmdata` sources carry the MIT
  header and could legally be taken with their notice, but they are built
  for running inside Cacti and are not needed. Its `WeatherMapDataSource_cacti.php`
  has no header at all: never take it.)
- **Option C**: a plugin runs in Cacti's process and calls its GPL
  functions. MIT is compatible with the GPL, so the plugin's own files can
  stay MIT; whoever distributes Cacti and the plugin together does so
  under the GPL's terms for the whole. Write `setup.php` and `INFO` from
  the API names in the documentation. Do not copy another plugin's
  `setup.php` (most Cacti plugins are GPL-2), nor the documentation's
  example plugin or snippets (the documentation repository has no licence
  file, only a Cacti Group copyright).
- **Option D** would also combine with GPL code at run time; same rule.
  The engine files taken from the plugin keep their MIT headers either way.
- Ideas taken from the Cacti plugin (target keywords, bulk import of its
  map list) are credited under "Ideas from other projects" in
  [THIRD-PARTY-NOTICES.md](../../THIRD-PARTY-NOTICES.md) when built, as
  for the 2.0 study.

## Recommendation and next roadmap items

1. **Cacti data source, database backend** (option A, part 1): hosts,
   interfaces, current values from DSStats, node state from `host.status`,
   `cacti:` targets, pickers in the editor.
2. **Cacti testbed container** (option A, part 3): Cacti polling the
   simulated devices in `deploy/testbed`, so 1 is checked on real data.
   Could come before 1 to have data to develop against.
3. **Cacti data source, RRD backend** (option A, part 2): values and
   history from Cacti's RRD files, traffic graph and link to Cacti's
   graph page.
4. **Import from the Cacti plugin, Cacti targets and map list** (option B).
5. **Cacti bridge plugin** (option C), only if users ask for a tab inside
   Cacti; with token sign-in and frame permission in NG.

Not recommended: the native plugin (option D). If it ever comes back,
the first step would be namespacing the engine and the helpers, which is
useful on its own and could be a small item.

These items are not added to the roadmap by this study: the maintainer
decides which ones to start.
