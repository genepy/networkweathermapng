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
- **English and French** - Chosen in the header, or with `APP_LOCALE`
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

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `production` |
| `APP_DEBUG` | Enable debug mode | `false` |
| `APP_TIMEZONE` | Application timezone | `UTC` |
| `APP_KEY` | Key that encrypts data source passwords (`base64:` + 32 bytes, or any passphrase). Without it a key is created in `DATA_ROOT/app.key`: back it up with the database | generated |
| `APP_ROOT` | Application root path | - |
| `DATA_ROOT` | Persistent data root path | - |
| `DB_TYPE` | Database type (`sqlite` or `mysql`) | `sqlite` |
| `AUTH_ENABLED` | Enable authentication | `true` |
| `ADMIN_USER` | Default admin username | `admin` |
| `ADMIN_PASSWORD` | Default admin password | `admin` |
| `MAP_OUTPUT_FORMAT` | Image format (`png`, `jpg`, `gif`) | `png` |
| `MAP_REFRESH_INTERVAL` | Map refresh interval in seconds | `300` |
| `APP_LOCALE` | Interface language when the browser's doesn't decide (`en`, `fr`) | browser language |

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
| `BANDWIDTH auto` (in a LINK) | Bandwidth read from the interface speed (Observium); an aggregated link adds up its members. Falls back to 1G when unknown |
| `SET link_spacing 12` | Gap in pixels between links that join the same two nodes. By default they are spread according to their width; `0` turns spreading off |

Resizing a map in the editor (by dragging its border, or in Map Properties)
asks whether to **scale the content** with it (nodes, VIA points, legend, title
and timestamp move proportionally, optionally keeping proportions and scaling
link widths) or only extend/crop the canvas.

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
  Ctrl+A for everything, or *Select…* (Ctrl+F) to pick by name, label,
  target, group, width or bandwidth.
- **Move**: drag the selection, or use the arrow keys (Shift for 10 px).
  *Grid* (G) snaps to 10 px. Links whose two ends move keep their shape.
- **Arrange**: align and distribute nodes, group them (Ctrl+G; Alt+click
  picks one member), lock them (Ctrl+L), bring to front or send to back.
  Groups and locks are stored as `SET editor_group` and `SET editor_lock`.
- **Links**: drag from the blue dot next to a selected node to another node,
  or use the *Link* tool (L). On a selected link, drag the red points to
  bend it, double-click one to remove it, and click a square around a node
  to attach that end to a side (`NODES a:N b:E`).
  *Auto-route* bends links around the nodes in their way; *Untangle labels*
  moves bandwidth labels that overlap (`BWLABELPOS`).
- **Add**: drag a node or an icon from the left panel, or double-click an
  empty spot.
- **Edit**: the right panel changes every selected item at once.
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

## Language and theme

The header has a language menu (English, French) and a theme button
(automatic, light, dark); both are remembered in cookies. Without a choice,
the language follows the browser, then `APP_LOCALE`. Translations live in
`src/app/Lang/<locale>/*.php`, one file per part of the application, as
`'English text' => 'translation'`; `*-js.php` files hold the texts of a
browser script. In code, wrap texts with `__('…')` (or `e__('…')` to print
them escaped) and add them to the catalogue.

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

- PHP 8.1+
- GD extension
- PDO extension (SQLite or MySQL)
- cURL extension
- Zabbix 5.0+ with API access

## License

This project is based on PHP Network Weathermap by Howard Jones and The Cacti Group.

Licensed under the MIT License. See LICENSE file for details.

## Credits

- Original Weathermap by Howard Jones
- Cacti Weathermap Plugin by The Cacti Group
- Zabbix integration and Docker containerization by the Zabbix Weathermap Team

## Support

- [Documentation](docs/)
- [Issue Tracker](https://github.com/nchekwa/networkweathermapng/issues)
