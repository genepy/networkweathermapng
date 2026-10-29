# NetworkWeathermapNG

![Network Weathermap Example 1](img/maps1.png)
![Network Weathermap Example 2](img/maps2.png)
![Network Weathermap Example 3](img/maps3.png)
![Network Weathermap Example 4](img/maps4.png)
![Network Weathermap Example 5](img/maps5.png)
![Network Weathermap Example 6](img/maps6.png)
![Network Weathermap Example 7](img/maps7.png)

Network visualization tool for Zabbix and Observium - a standalone PHP application that creates network weathermaps using data from your monitoring system.

## Overview

NetworkWeathermapNG is based on the popular Cacti Weathermap plugin but redesigned to work as a standalone Docker container.

## Features

- **Standalone Docker Container** - No dependencies on Cacti or other systems
- **Zabbix API Integration** - Fetches real-time data from Zabbix
- **Observium Integration** - API, MySQL or RRD, Community edition included
- **Visual Map Editor** - Create and edit maps through web interface
- **Multiple Maps** - Organize maps into groups
- **Map Cycling** - Auto-rotate through maps for NOC displays
- **Map Management** - Create, rename, duplicate, export and delete maps (with all their data) from Admin > Maps
- **Migration** - Import maps from the Cacti plugin or older Weathermap versions
- **User Authentication** - Built-in authentication system
- **Translations** - English and French built in, chosen in the header or with `APP_LOCALE`; [add your language](TRANSLATING.md)
- **Light and dark themes** - Automatic (system setting) or chosen in the header
- **Responsive UI** - Modern, mobile-friendly interface

## Quick Start

### Using Docker Compose

1. Clone the repository:
   ```bash
   git clone https://github.com/genepy/networkweathermapng.git
   cd networkweathermapng
   ```

2. Copy and configure environment:
   ```bash
   cp .env.example .env
   # Edit .env if you want to change app/db/auth/map settings
   ```

3. Start the container:
   ```bash
   cd docker
   docker-compose up -d
   ```

4. Access the application:
   ```
   http://localhost:8080
   ```

   Default credentials: `admin` / `admin`

Everything that changes is kept in the `/app/data` volume: the database,
map configurations, rendered images, editor history, migrations, and the
icons and backgrounds (`/app/public/objects` and `/app/public/backgrounds`
point there; the images shipped with the application are copied in at
start, without replacing yours). Uploads, a migration zip included, are
limited to 64 MB.

### Test deployment with Observium

`deploy/testbed` runs Observium, Weathermap and a few simulated SNMP devices
on one machine (Raspberry Pi 4, Oracle Cloud Ampere, any Linux host with
Docker), tuned to spare an SD card, and can be deployed from GitHub Actions on
a self-hosted runner. See [deploy/testbed/README.md](deploy/testbed/README.md).

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `production` |
| `APP_DEBUG` | Enable debug mode | `false` |
| `APP_TIMEZONE` | Application timezone | `UTC` |
| `APP_CSP` | Content-Security-Policy sent with every page; `off` sends none | same-site resources only |
| `APP_KEY` | Key that encrypts data source passwords (`base64:` + 32 bytes, or any passphrase). Without it a key is created in `DATA_ROOT/app.key`: back it up with the database | generated |
| `APP_ROOT` | Application root path | - |
| `DATA_ROOT` | Persistent data root path | - |
| `DB_TYPE` | Database type (`sqlite` or `mysql`) | `sqlite` |
| `AUTH_ENABLED` | Enable authentication | `true` |
| `ADMIN_USER` | Default admin username | `admin` |
| `ADMIN_PASSWORD` | Default admin password | `admin` |
| `MAP_OUTPUT_FORMAT` | Image format (`png`, `jpg`, `gif`) | `png` |
| `MAP_REFRESH_INTERVAL` | Map refresh interval in seconds | `300` |
| `APP_LOCALE` | Default interface language (`en`, `fr`, or any folder of `src/app/Lang/`) when no language was chosen in the header | browser language |

### Data sources (Zabbix and more)

Data sources are configured via the web UI and stored in the database:

- Go to **Admin → Data Sources**
- Add your Zabbix API endpoint and credentials there


## Map Configuration

Maps are configured using text-based configuration files. Example:

```
# Map settings
BACKGROUND images/backgrounds/network.png
WIDTH 1200
HEIGHT 800
TITLE My Network Map

# Define a node
NODE router1
    LABEL Core Router
    POSITION 100 100
    ICON images/icons/router.png
    TARGET zabbix:hostid:10084:key:system.cpu.util

# Define another node
NODE switch1
    LABEL Main Switch
    POSITION 300 100
    TARGET zabbix:hostid:10085:key:system.cpu.util

# Define a link between nodes
LINK router1-switch1
    NODES router1 switch1
    TARGET zabbix:hostid:10084:key:net.if.in[eth0]:net.if.out[eth0]
    BANDWIDTH 1G
```

### Sizing and layout

| Setting | Effect |
|---------|--------|
| `WIDTH auto` (in a LINK) | Width follows the bandwidth: 2 px up to 1G, 4 px for 10G, 5 px for 25G, 6 px for 40G, 9 px for 100G, 12 px for 400G |
| `SET link_width_table 1G:2 10G:4 100G:9` | The map's own speed → width table for `WIDTH auto` |
| `BANDWIDTH auto` (in a LINK) | Bandwidth read from the interface speed (Observium, or Zabbix for links picked in the editor: the `net.if.speed[...]` item of the SNMP templates, or the sysfs speed of the Linux agent); an aggregated link adds up its members. Falls back to 1G when unknown |
| `SET width_legend 10 -10` | Draws a legend of the `WIDTH auto` widths at x, y (negative values count from the right or bottom edge) |
| `SET width_legend_title Speed` | Title of that legend (default `Link speed`) |
| `SET link_spacing 12` | Gap in pixels between links that join the same two nodes. By default they are spread according to their width; `0` turns spreading off |

Resizing a map in the editor (by dragging its border, or in Map Properties)
asks whether to **scale the content** with it (nodes, VIA points, legend, title
and timestamp move proportionally, optionally keeping proportions and scaling
link widths) or only extend/crop the canvas.

### Zones (site rectangles)

A `ZONE` block draws a labelled rectangle under every link and node, to show a
site, a room or an area. Zones come after the global settings, before or among
the NODE and LINK blocks:

```
ZONE paris
	POSITION 20 30          # top-left corner
	SIZE 400 250            # width and height
	LABEL Paris DC
	LABELPOS NW             # NW, N, NE, SW, S or SE, inside the box
	LABELFONT 3
	FILLCOLOR 235 242 255   # or none
	OPACITY 100             # fill opacity, in percent
	OUTLINECOLOR 120 140 180
	OUTLINEWIDTH 1
	OUTLINESTYLE dashed     # or solid
	LABELFONTCOLOR 40 60 100
```

Everything but `POSITION` and `SIZE` is optional. In the SVG editor, the
**Zone** button adds one (around the selected nodes, if any). A zone is picked
by its edge or its label, so its inside stays free for selecting nodes. Dragging
a zone moves the nodes inside it too; hold Alt to move the zone alone. The
corner handle resizes it, and the inspector edits its label and style.

### Zabbix TARGET Formats

| Format | Description |
|--------|-------------|
| `zabbix:itemid:12345` | Single item by ID |
| `zabbix:itemid:12345:12346` | Two items (in/out) by ID |
| `zabbix:host:hostname:key:item.key` | Item by hostname and key |
| `zabbix:hostid:10084:key:item.key` | Item by host ID and key |
| `zabbix:hostid:10084:key:in.key:out.key` | Two items by host ID and keys |

### Observium TARGET Formats

| Format | Description |
|--------|-------------|
| `observium:port:1234` | Port by its Observium `port_id` |
| `observium:host:core-rtr1:if:xe-0/0/1` | Port by device hostname and interface name (ifName, ifDescr or ifAlias) |
| `observium:host:core-rtr1:status` | Device status for a node: 0 when up, 100 when down |

Add the source in **Admin → Data Sources** with the type *Observium*. The
editor's interface picker fills in the TARGET and the link bandwidth from the
interface speed.

Weathermap reads Observium in one of three ways, and maps work the same with all
of them:

- **API** (Professional and Enterprise editions): set the Observium URL and an
  API token, or a user and password.
- **Database** (every edition, Community included): leave the credentials empty.
  On the Observium server nothing else is needed, the database settings are read
  from `/opt/observium/config.php` (the file is parsed, never executed). For a
  remote Observium, fill in the database host and a read-only MySQL user:
  `GRANT SELECT ON observium.* TO 'weathermap'@'%' IDENTIFIED BY '...'`.
- **RRD files**: used for the hover graphs and when no current rate is
  available. Set the RRD folder, or an rrdcached address for a remote Observium.

With Docker on the Observium server, uncomment the Observium volumes in
`docker/docker-compose.yml` and use `host.docker.internal` as database host.

## Map editor

The editor (Editor > Edit) draws the map as SVG, so zooming stays sharp,
and saves each change straight into the map's `.conf` file.

- **Select**: click, Shift+click to add, drag on empty space for an area,
  Ctrl+A for everything (zones, legends, timestamps and title included), or
  *Select…* (Ctrl+F) to pick by name, label, target, group, width or
  bandwidth. Esc cancels a drag, closes the open menu, or empties the
  selection.
- **Move**: drag the selection, or use the arrow keys (Shift for 10 px).
  Nodes, zones, legends (`KEYPOS`), timestamps (`TIMEPOS`) and the title
  (`TITLEPOS`) move alike. While dragging, pink guides show the edges and
  centres the selection lines up with, and it snaps to them (hold Alt not
  to). *Grid* (G) snaps to 10 px. Links whose two ends move keep their shape.
  A zone moves alone: group it with its nodes to move them together.
- **Arrange**: align and distribute nodes, zones, legends and the title,
  group nodes, links and zones (Ctrl+G; Alt+click picks one member), lock
  them (Ctrl+L), bring to front or send to back. Groups and locks are stored
  as `SET editor_group` and `SET editor_lock`.
- **Links**: drag from the blue dot next to a selected node to another node,
  or use the *Link* tool (L). Double-click a link to add a bend point there;
  on a selected link, drag the red points to bend it (as many as needed),
  double-click one to remove it, and click a square around a node to attach
  that end to a side (`NODES a:N b:E`).
  *Auto-route* bends links around the nodes in their way; *Untangle labels*
  moves bandwidth labels that overlap (`BWLABELPOS`).
- **Labels**: drag a bandwidth label of the selected link along it
  (`BWLABELPOS`), or the label of a selected node with an icon away from the
  icon (`LABELOFFSET`).
- **Add**: drag a node or an icon from the left panel, or Ctrl+click an
  empty spot.
- **Edit**: the right panel changes every selected item at once.
- **Copy and paste**: Ctrl+C / Ctrl+X / Ctrl+V copy the selected nodes and
  the links between them, also into another map open in another tab (the
  copy is kept in the browser). Pasted items take free names (`name_2`…),
  land under the mouse or next to the originals, and keep their VIAs and
  templates. Only NODE and LINK blocks are accepted.
- **Scale a layout**: with two nodes or more selected, drag the handles of
  the selection box to spread or tighten them (Shift keeps proportions).
- **Right-click** (or Shift+F10) opens a menu with the usual actions.
- **Overlaps**: overlapping nodes are outlined; *Spread apart* moves them
  (or only the selected ones) at least 10 px apart.
- **Layers**: hide the background, links, link labels or node labels while
  editing. This is remembered in the browser, never saved in the map.
- **Text mode**: edit the `.conf` itself, checked by the engine as you type
  (warnings with their line). *Apply* saves it as one undo step. INCLUDE and
  the output paths (`*OUTPUTFILE`, `IMAGEURI`) cannot be changed there.
- **Undo**: Ctrl+Z and Ctrl+Shift+Z, kept on disk under
  `DATA_ROOT/history/<map id>`, so they survive a reload.

The previous editor is still available at `/editor/classic/<map id>`.

### Icons

`objects/net/` holds a set of network icons (router, switches, firewall,
server, cloud, internet, Wi-Fi, load balancer, storage, workstation,
virtualisation host, site, VPN) as SVG with a PNG twin. Maps reference the
`.png` (or the `.svg`): the PNG output is drawn from the PNG, and the SVG
output and the editor use the vector file, so icons stay sharp when zoomed.
Any icon with an `.svg` next to it gets the same treatment, unless it is
coloured by `USEICONSCALE`.

## Managing maps

Admin > Maps lists every map:

- **New map**: empty (size of your choice) or a copy of an existing map.
- **Properties**: name (the `TITLE` follows), group, visibility, and
  optionally renaming the `.conf` file after the name.
- **Duplicate**: choose the copy's name, group and visibility.
- **.conf** / **Export all**: download one configuration, or a zip of every
  map with its icons and backgrounds, which Admin > Migration imports back.
- **Delete**: removes the map and everything kept for it: configuration and
  backup, rendered images and pages, link graphs, editor history,
  permissions and settings.
- Tick several maps to activate, deactivate, move, export or delete them at
  once.

## Viewing maps

- **Map list** (`/`): type in the search box to filter maps by name (Enter opens the first match). The star on a card makes the map a favourite for your account; favourites come first. The badge on each card shows the state of the map: the colour and load of its most loaded link at the last render ("Max load 87 %" on hover). Maps without a recent render are refreshed in the background.
- **Map view** (`/map/{id}`): the map is drawn as SVG and can be zoomed with the mouse wheel or a pinch, moved by dragging, or with the toolbar (+, −, Fit, 100 %) and the keyboard (`+`, `-`, `0` fit, `1` actual size, arrows, `/` to search). "Find a node" centres the map on a node by label or name. Bandwidth labels are hidden when they would be too small to read. A minimap shows where you are when the map does not fit. The zoom and position are kept in the address (`#z=2&x=..&y=..`): a shared link opens on the same view. Zooming never changes the saved map.
- Hovering a link shows its current in/out traffic and, when it has a data source, the last 24 hours; clicking a link or node opens its INFOURL in a new tab.
- The view follows new data every `MAP_REFRESH_INTERVAL` seconds and right away after an edit, keeping the zoom. The SVG is cached next to the PNG (`DATA_ROOT/output/<map>.svg`, with the state summary in `<map>.json`) and drawn again in the same pass as the PNG.
- **Fullscreen** fits the map to the screen for a NOC wall, follows the light/dark theme and hides the toolbar when the mouse is idle; Esc leaves it. The **PNG** button keeps the classic picture (also used for thumbnails, exports and the cycle page).

## Language and theme

The header has a language menu and a theme button (automatic, light,
dark); both are remembered in cookies. Without a choice, the language comes
from `APP_LOCALE`, then the browser, else English. Translations live in
`src/app/Lang/<locale>/*.php`, one file per part of the application, as
`'English text' => 'translation'`; `*-js.php` files hold the texts of a
browser script and `_meta.php` gives the language name. Every folder there
appears in the language menu. In code, wrap texts with `__('…')` (or
`e__('…')` to print them escaped) and add them to the catalogues;
`php src/bin/i18n-check.php` lists missing and unused keys.

To add or improve a language, see [TRANSLATING.md](TRANSLATING.md).

## Directory Structure

```
networkweathermapng/
├── docker/                 # Docker configuration
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── nginx/
│   ├── php/
│   └── supervisor/
├── src/
│   ├── public/            # Web root
│   ├── app/               # Application code
│   │   ├── Core/          # Core framework
│   │   ├── Controllers/   # HTTP controllers
│   │   ├── Services/      # Business logic
│   │   └── Views/         # Templates
│   └── lib/               # Libraries
│       ├── WeatherMap/    # Map engine
│       └── Zabbix/        # Zabbix API client
├── data/
│   ├── configs/           # Map configuration files
│   ├── output/            # Generated images
│   └── cache/             # Cache files
└── docs/                  # Documentation
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/maps` | GET | List all maps |
| `/api/map/{id}` | GET | Get map details |
| `/api/zabbix/hosts` | GET | List Zabbix hosts |
| `/api/zabbix/items/{hostId}` | GET | List items for a host |
| `/api/zabbix/groups` | GET | List Zabbix host groups |

## GitHub Actions: Build & Publish Docker image

This repository includes a GitHub Actions workflow that builds and publishes a Docker image to GitHub Container Registry (GHCR).

- **Workflow file**: `.github/workflows/docker-publish.yml`
- **Image name**: `ghcr.io/<owner>/<repo>`

### Triggers

- Push to `main`:
  - Publishes `latest` and a `sha-...` tag
- Push a version tag (e.g. `v1.2.3`):
  - Publishes `v1.2.3` and a `sha-...` tag
- Manual run:
  - You can run the workflow from the Actions tab

### Required GitHub settings

- Ensure the repository has **Workflow permissions** allowing writing packages (the workflow uses `GITHUB_TOKEN` with `packages: write`).
- After the first push, you will see the package under:
  - `https://github.com/<owner>?tab=packages`

## Development

### Local Development

```bash
# Install dependencies
composer install

# Start development server
composer start
# or
php -S localhost:8080 -t src/public
```

### Running Tests

```bash
composer install
vendor/bin/phpunit                 # engine render tests + application tests
vendor/bin/phpstan analyse         # static analysis
```

Render tests draw every map in `tests/fixtures/maps/` and compare it with
`tests/fixtures/reference/`. After an intended visual change, regenerate the
references with `UPDATE_REFERENCE=1 vendor/bin/phpunit` and review the images
before committing them.

## Migration from an existing Weathermap

Maps from the Cacti plugin or from older Weathermap versions are imported in
**Admin → Migration**: upload one or more `.conf` files, or a `.zip` holding
the `.conf` files with their icons and backgrounds.

For each map the review page shows:

- **Checks**: `INCLUDE` lines are removed (they read files on the old server),
  the old `*OUTPUTFILE` / `IMAGEURI` settings are ignored, engine warnings.
- **Images**: found, found under `objects/` or `backgrounds/` by file name, or
  missing. Images from the zip go to `objects/` (or `backgrounds/` when a map
  uses them as `BACKGROUND`); an existing file with the same name is never
  replaced. PNG, JPEG, GIF and SVG are accepted. SVG files are cleaned first:
  only drawing elements and attributes are kept (no scripts, event handlers,
  `foreignObject` or links out of the file; a `DOCTYPE`/`ENTITY` is refused),
  then a PNG copy is drawn next to them for the map engine with `rsvg-convert`
  (in the Docker image) or ImageMagick. Without either, SVG files are skipped.
- **Targets**: pick a data source and the old RRD, SNMP and MRTG targets are
  matched to its interfaces by device name and ifIndex or interface name
  (Observium `rrd/<host>/port-<ifIndex>.rrd`, `snmp:community:host:oid:oid`,
  Cacti `host_traffic_in_N.rrd`, MRTG `host_N.log`). One match is selected
  for you; when several devices or interfaces fit, choose in the list, or type
  a target. `-` and `N*` in front of the old target are kept. Commands
  (`!…`) are never carried over: replace them in the editor.
- **Fonts**: the GD bitmap fonts 1–5 can be replaced by DejaVu Sans Mono
  TrueType fonts of the same size (`FONTDEFINE 101`–`105`).

**Compare old and new** shows the old engine's PNG next to the new SVG, or
overlaid with the differences highlighted. **Create the map** saves it and
opens the editor; the original file is kept in the map's history, so undo
goes back to it, and **Restore original** on the review page puts it back.
Positions, VIAs and every other setting are kept as they are. Live data is
never read while migrating.

Many maps at once, from the command line (in the Docker image, from `/app`,
e.g. `docker exec -it networkweathermapng php bin/migrate.php …`):

```bash
php bin/migrate.php maps/*.conf                    # analyse only
php bin/migrate.php --source=1 --apply maps.zip    # create the maps
php bin/migrate.php --source=1 --no-fonts --group=2 --apply configs/
```

Targets with a single match are converted; the others keep their old value
and are listed with a link to their review page.

## Requirements

- PHP 8.1+, on a release that has the latest security fixes. CVE-2026-109618
  and CVE-2026-109619 (remote code execution, October 2026) are fixed in
  PHP 8.5.12, 8.4.27, 8.3.36 and 8.2.35. PHP 8.1 has no official fix: use a
  distribution package that backports it, or upgrade. The Docker image ships
  PHP 8.5.12.
- GD extension
- PDO extension (SQLite or MySQL)
- cURL extension
- Zabbix 5.0+ with API access

## License

Licensed under the MIT License. See [LICENSE](LICENSE).

This project is based on PHP Network Weathermap by Howard Jones and on the
Weathermap plugin for Cacti by The Cacti Group. The code, icons, fonts and
packages that come from other projects, with their authors and licenses, are
listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Credits

- Original Weathermap by Howard Jones and contributors
- Cacti Weathermap Plugin by The Cacti Group
- Zabbix edition, Zabbix integration and Docker containerization by Artur Zdolinski (nchekwa)
- Network icons from Network-Icons-SVG by aci686 and BobTheButcher
- DejaVu fonts by the DejaVu team, based on Bitstream Vera

## Support

- [Documentation](docs/)
- [Issue Tracker](https://github.com/nchekwa/networkweathermapng/issues)
