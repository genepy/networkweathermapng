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
- **Kiosk** - Auto-rotate through maps for NOC displays, with a zoom and a display time per map (Settings > Kiosk), a progress bar and controls that fade out
- **Time zone** - Dates shown on maps use a default time zone, and each map can have its own (Settings > Time zone, or the map's *Map settings*)
- **Map Management** - Create, duplicate, reorder, export and delete maps (with all their data) from the Maps page; every setting of a map (name, group, time zone, kiosk, recording, access) in one *Map settings* panel
- **Migration** - Import maps from the Cacti plugin or older Weathermap versions
- **User Authentication** - Built-in authentication system
- **Translations** - English and French built in, chosen in the user menu or with `APP_LOCALE`; [add your language](TRANSLATING.md)
- **Geographic basemap** - A map can float on a web map (OpenStreetMap vector tiles from OpenFreeMap, or your own tile server), with nodes placed by latitude and longitude; see *Geographic basemap* below
- **Light and dark themes** - Automatic (system setting) or chosen in the user menu; colours meet WCAG 2.2 AA contrast in both
- **Responsive UI** - A thin top bar with a user menu and a `Ctrl+K` command palette (maps, nodes, settings, commands), a bottom navigation bar on phones, toasts for messages and a styled confirmation dialog

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

### Demo maps

Three demo maps show what a map can do, with traffic from fixed values (the
static data source, `TARGET static:<in>:<out>`), so they draw without Zabbix
or Observium:

| Map | What it shows |
|-----|---------------|
| *France sites* | A geographic basemap with nodes placed by latitude and longitude; several sites in Paris, Lyon, Marseille and Toulouse merge into numbered clusters until you zoom in, with the city inset on hover; round badge nodes, pill labels, traffic flow, pulsing saturated links, sites in the side panel |
| *Datacenter fabric* | Leaf and spine with border routers and racks, vector (SVG) icons, zones, link widths by speed, comments along curved links |
| *ISP backbone* | Core, transit and peering routers in points of presence, Night theme with glowing links, long-haul links that curve with their comments, a speed legend |

Add them to a "Demo maps" group with:

```bash
docker compose exec weathermap php bin/demo-maps.php      # or: php src/bin/demo-maps.php
```

Maps that already have the name of a demo are left as they are, so running it
again changes nothing. The testbed adds them too. Their files are in
`src/examples/maps`: open one in the editor to see how a feature is set up.

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
| `APP_TIMEZONE` | Application timezone, and the time zone of map dates until one is chosen in Settings > Time zone | `UTC` |
| `APP_CSP` | Content-Security-Policy sent with every page; `off` sends none | same-site resources only |
| `BASEMAP_SERVERS` | Tile servers a map's basemap may load from (space-separated URL prefixes), added to the default Content-Security-Policy. Same-site addresses (`/tiles/...`) are always allowed | `https://tiles.openfreemap.org` |
| `APP_KEY` | Key that encrypts data source passwords (`base64:` + 32 bytes, or any passphrase). Without it a key is created in `DATA_ROOT/app.key`: back it up with the database | generated |
| `APP_ROOT` | Application root path | - |
| `DATA_ROOT` | Persistent data root path | - |
| `DB_TYPE` | Database type (`sqlite` or `mysql`) | `sqlite` |
| `AUTH_ENABLED` | Enable authentication | `true` |
| `ADMIN_USER` | Default admin username | `admin` |
| `ADMIN_PASSWORD` | Default admin password | `admin` |
| `MAP_OUTPUT_FORMAT` | Image format (`png`, `jpg`, `gif`), until one is chosen in Settings > Maps & rendering | `png` |
| `MAP_REFRESH_INTERVAL` | Map refresh interval in seconds (10 to 86400), until one is chosen in Settings > Maps & rendering | `300` |
| `APP_LOCALE` | Default interface language (`en`, `fr`, or any folder of `src/app/Lang/`) when no language was chosen in the user menu | browser language |

### Data sources (Zabbix and more)

Data sources are configured via the web UI and stored in the database:

- Go to **Settings → Data sources** and click **Add data source**
- Add your Zabbix API endpoint and credentials in the panel that opens on the
  right; the type you choose only shows its own fields
- **Test** on the source's row checks the connection: the dot turns green or
  red, the row says when it was tested, and *Request and response* shows the
  request sent and the answer received. The ⋯ menu edits or deletes it


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
| `WIDTH auto` (in a LINK) | Width follows the bandwidth: 2 px up to 1G, 4 px for 10G, 5 px for 25G, 6 px for 40G, 9 px for 100G, 10 px for 200G, 12 px for 400G, 15 px for 800G |
| `SET link_width_table 1G:2 10G:4 100G:9` | The map's own speed → width table for `WIDTH auto` |
| `BANDWIDTH auto` (in a LINK) | Bandwidth read from the interface speed (Observium, or Zabbix for links picked in the editor: the `net.if.speed[...]` item of the SNMP templates, or the sysfs speed of the Linux agent); an aggregated link adds up its members. Falls back to 1G when unknown |
| `SET width_legend 10 -10` | Draws a legend of the `WIDTH auto` widths at x, y (negative values count from the right or bottom edge); no hint, no legend |
| `SET width_legend_title Speed` | Title of that legend (default `Link speed`) |
| `KEYSTYLE DEFAULT modern 240` | Legend as a rounded card with a colour bar 240 px long and the 0 to 100% marks under it (other styles: `classic`, `horizontal`, `vertical`, `inverted`, `tags`) |
| `SET timestamp_style pill` | Draws the timestamps on a rounded label in the legend colours (`KEYBGCOLOR`, `KEYOUTLINECOLOR`) |
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

### Map notes

A note is free text on the map, on several lines: a legend, a contact, a
maintenance window. It is a node without an icon, marked with a hint, in the
format PHP Weathermap 2.0 uses, so its maps import with their notes:

```
NODE note1
	POSITION 120 60         # centre of the note
	LABEL Maintenance: Sunday 02:00\nCall the NOC on 1234
	LABELFONT 100
	SET editor_note 1
	SET editor_note_escaped 1   # \n starts a new line, \\ is a backslash
	SET editor_note_align left  # left (default), centre or right
```

The note uses the node label style (`LABELFONT`, `LABELBGCOLOR` none for no
box, `LABELOUTLINECOLOR`, `LABELFONTCOLOR`) and is drawn sharp in the PNG and
the SVG. In the SVG editor, the **Note** button (or **Add a note here** in the
right-click menu) adds one; the inspector edits its text, where Enter starts a
new line, its alignment and its style.

### Comments along curved links

`INCOMMENT` and `OUTCOMMENT` are drawn straight, along the link where they
start. With `COMMENTCURVE 1` (the PHP Weathermap 2.0 keyword; `0`, straight,
is the default) a comment follows a curved link, letter by letter, in the
PNG and the SVG:

```
LINK DEFAULT
	COMMENTCURVE 1          # every link; or in one LINK only
	COMMENTFONT 100         # a TrueType font: GD's bitmap fonts cannot turn
```

The text is never upside down and keeps `COMMENTPOS` and `COMMENTSTYLE`. It
stays straight where the link is straight, where the link bends too much
under it (a right angle, a tight curve), on several lines, or in a bitmap
font. In the SVG editor, *Along the link* in the Comments part of a link's
panel switches it.

### Geographic basemap

A map can be drawn over a web map: streets, coasts and place names under
the nodes and links, in the live view and in the SVG editor. The canvas
keeps its size in pixels; the basemap says which area it covers:

```
SET basemap https://tiles.openfreemap.org/styles/positron   # MapLibre style, or raster tiles .../{z}/{x}/{y}.png
SET basemap_dark https://tiles.openfreemap.org/styles/dark  # optional, for the dark theme
SET basemap_center 46.6 2.4     # latitude and longitude at the centre of the canvas
SET basemap_zoom 5              # web map zoom level at 100 % (0 world, 5 country, 11 city)
SET basemap_attribution ...     # optional credit; raster tiles default to OpenStreetMap

NODE paris
	POSITION 398 147
	SET geo 48.8566 2.3522      # sits on this point; POSITION follows
```

A node with `SET geo` is placed on its point whenever the map is read, so
changing the centre or the zoom moves it with the basemap; nodes without it
keep their position. In the editor: *Map properties > Basemap* (presets,
addresses, centre, zoom, *Frame the located nodes*), and *Location* in the
node panel (latitude and longitude, *Locate where it is*, *Remove the
location*). Nodes added on a basemap are located where they are dropped;
moving a located node moves its point. The *Background* layer switch hides
the tiles while editing.

The tiles are drawn by the visitor's browser with MapLibre GL JS (shipped,
loaded only for these maps; needs WebGL) and only from the servers of
`BASEMAP_SERVERS` or from the site itself, so a closed network serves its
own tiles under the same address. In the viewer the map zooms in further on a
basemap (up to 64×). The PNG image and the downloaded SVG keep the plain
background. Design note: [docs/studies/geographic-basemap.md](docs/studies/geographic-basemap.md).

In the viewer, located nodes keep the size they have at 100 % when you zoom
in, so zooming pulls close sites apart. Located nodes too close to tell apart
at the current zoom merge into one round badge with their number (its ring
takes the colour of their busiest link); a click on it zooms until they split,
and nodes on the very same point are listed instead. Hovering a site, a
cluster or a row of the side panel shows its city in a small web map in the
top right corner, with the located nodes and their links (a node with
`SET site` shows its whole site); the city of the focused node stays there.
Bandwidth labels are hidden when they would grow over 40 pixels.

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

Add the source in **Settings → Data sources** with the type *Observium*. The
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

The editor (*Edit* on the Maps page or in the map view) draws the map as
SVG, so zooming stays sharp, and saves each change straight into the map's
`.conf` file.

- **Top bar**: back to the maps, the map name, whether the last change is
  saved (*Saving…*, *Saved*, *Not saved*, in a fixed width so the bar does
  not move), undo and redo; in the middle the
  `Ctrl+K` search (see below); on the right *Layers*, *Text mode*, a ⋯ menu (*Map properties*, *Map settings*,
  *Toolbars and panels*, *Keyboard shortcuts*, theme, the map in a new tab)
  and *View*, which opens the map view.
- **Keyboard shortcuts**: `?` (or ⋯ › *Keyboard shortcuts*) lists them all.
- **Contextual bar**: while something is selected, a small bar floats over
  it with what applies: *Align and space evenly* (a menu), *Group* or
  *Ungroup*, *Lock*, *Front*, *Back*, *Link* (the two selected nodes, or
  from the selected one), *Duplicate*, *Delete* and ⋯ (the menu of actions,
  also on a right-click or Shift+F10). A small rail at the top left of the
  drawing holds *Select* (V), *Link* (L) and *Grid* (G); the zoom sits at the
  bottom right next to the overview map. The movable toolbars of earlier
  versions come back with ⋯ › *Toolbars and panels* › *Movable toolbars*
  (remembered in the browser); the contextual bar is not shown then.
- **Search** (`Ctrl+K`, `⌘K` on a Mac, or the search box of the top bar):
  every editor command that applies now, with its shortcut (the ones for
  the selection first), the nodes, links and zones of the map (Enter
  selects one and zooms onto it), then the maps, nodes of other maps and
  Settings pages of the application-wide palette.

- **Select**: click, Shift+click to add, drag on empty space for an area,
  Ctrl+A for everything (zones, legends, timestamps and title included), or
  *Select…* (Ctrl+F) to pick by name, label, target, group, width or
  bandwidth. Esc cancels a drag, closes the open menu, or empties the
  selection.
- **Move**: drag the selection, or use the arrow keys (Shift for 10 px).
  Nodes, zones, legends (`KEYPOS`), timestamps (`TIMEPOS`) and the title
  (`TITLEPOS`) move alike. While dragging, pink guides show the edges and
  centres the selection lines up with, and it snaps to them (hold Alt not
  to). *Grid* (G) snaps to 10 px; its lines are light on a dark background
  colour, dark on a light one, and dark with a light halo over a picture or
  a basemap. Links whose two ends move keep their shape.
  A zone moves alone: group it with its nodes to move them together.
  Hold Ctrl (Cmd on a Mac) while dragging to drop a copy of the selected
  nodes, and of the links between them, instead of moving them.
- **Arrange**: align and distribute nodes, zones, legends and the title
  (a group moves as one block, unless everything selected is one group),
  group nodes, links and zones (Ctrl+G; Alt+click picks one member), lock
  them (Ctrl+L), bring to front or send to back. Groups and locks are stored
  as `SET editor_group` and `SET editor_lock`.
- **Links**: drag from the blue dot next to a selected node to another node,
  or use the *Link* tool (L). Double-click a link to add a bend point there;
  on a selected link, drag the red points to bend it (as many as needed),
  double-click one to remove it, and click a square around a node to attach
  that end to a side (`NODES a:N b:E`).
  *Auto-route* bends links around the nodes in their way; *Tidy ends* starts
  and ends the selected links on the facing sides of their nodes, so they run
  straight, and spreads the links between the same two nodes
  (`NODES a:24:0 b:-24:0`); *Untangle labels* moves bandwidth labels that
  overlap another label or a node, as they are drawn (`BWLABELPOS`, and a
  few pixels to one side with `SET bwlabel_offset_in` / `bwlabel_offset_out`):
  only the labels of the selected links when links are selected (button in
  the selection bar, the right-click menu or Ctrl+K), every label otherwise.
  Each label slides along its own half of the link or steps aside; several
  passes let the labels make room for each other, and the labels that found
  no free place are named. *Spread apart* in the selection bar does the same
  for the selected nodes that overlap.
- **Link data**: *Bandwidth* sets both directions; *Out, if different* sets
  the outbound one alone (`BANDWIDTH 1G 100M`). *Info URL* is the page a
  click opens (`INFOURL`) and *Hover graph* the picture shown on hover
  (`OVERLIBGRAPH`, addresses separated by spaces); a node has both too.
- **Labels**: drag a bandwidth label with the mouse, no need to select the
  link first (the pointer turns into a move cursor over it): along the link
  (`BWLABELPOS`) or up to 60 px to one side (`SET bwlabel_offset_in N`,
  `SET bwlabel_offset_out N`, positive to the left going from A to B).
  *Labels* in the link panel shows the load in %, the traffic, the raw
  value or no label for the selected links (`BWLABEL percent`, `bits`,
  `unformatted`, `none`), and *Label* in *Link defaults* for every link.
  Or drag the label of a selected node with an icon away from the
  icon (`LABELOFFSET`). *Label position* in the node panel puts the label
  above, at the top, in the centre, at the bottom or below the icon in one
  click, for every selected node (`SET editor_label_position N`, `N60`, `C`,
  `S60` or `S`, the PHP Weathermap 2.0 hint; a drag replaces it).
- **Add**: the *Add* panel on the left has four tiles: *Node* (click to add
  it in the middle of the view, or drag it onto the map), *Note*, *Zone* and
  *Link* (L). Below them come the node templates of the map, the icon
  library (themes that fold, a search; click an icon to add a node with it,
  or drag it) and *Backgrounds* (click one to set the background of the map,
  one undo step). Ctrl+click on an empty spot also adds a node. *Map items*
  puts the *Link load* legend (`KEYPOS`), the *Link speed* legend
  (`SET width_legend`) or the *Timestamp* (`TIMEPOS`) in the middle of the
  view, or selects it when it is already on the map.
- **Delete**: Delete (or Backspace, the *Delete* button of the selection
  bar, of the right panel or of the context menu) removes the selection. A
  legend or a timestamp is hidden rather than removed: it stays in the
  `.conf` at a negative position (`KEYPOS DEFAULT -681 -21`,
  `TIMEPOS -11 -491 ...`, kept as -x - 1, -y - 1 so it comes back where it
  was) and the link speed legend loses its `SET width_legend`. Ctrl+Z
  undoes it; the *Add* panel or the box of its section in the map panel
  shows it again. The title stays: it shows the map name.
- **Edit**: the right panel changes every selected item at once.
- **Map properties**: with nothing selected, the right panel shows the map:
  name, size, background, then sections that fold, each with its value on
  its row (*Background colour and theme*, *Title*, *Link load legend*,
  *Link colours*, *Link speed legend*, *Timestamp*, *Link defaults*,
  *Basemap*, *Fonts*). *Link load legend*, *Link speed legend* and
  *Timestamp* each have a box that shows or hides the item on the map. A field is saved when
  it changes, as one step Ctrl+Z undoes; the colour scale is saved with its
  *Apply the colours* button. The browser remembers which sections are
  open. A selected legend, title, timestamp or link speed legend shows its
  own section. The
  width of all the links of a map is set in *Link defaults* (a number of
  pixels, or `auto` to follow the bandwidth: `WIDTH auto` in `LINK DEFAULT`).
- **Link colours**: the *Link colours* section of *Map properties* edits the
  colour scale of the links (`SCALE` lines of the DEFAULT scale, in % of the
  bandwidth): one row per step, with a preview bar. Presets start from the
  classic Weathermap colours, a traffic light, a colour-blind safe scale
  (Okabe and Ito colours), a heat scale or a single blue that darkens with
  the load. The presets but the classic one read on light and dark
  backgrounds. A step that fades between two colours keeps them.
- **Saturated links**: the same section has a switch that makes the links at
  80 % or more (in either direction) pulse and glow on the live map
  (`SET traffic_pulse 1`, the PHP Weathermap 2.0 hint). Under the system's
  *reduce motion* setting the glow stays and the pulse stops. The PNG is not
  changed.
- **Glow**: another switch of that section lights up the link under the
  pointer with a soft glow in its own colour (`SET link_glow 1`), in the live
  map and the editor. There is no glow at rest.
  The PNG keeps plain links.
- **Traffic flow**: a third switch of that section makes small glowing comets
  run along the links on the live map (`SET link_flow 1`), each half of a
  link from where its traffic leaves to its arrow head (the whole link for
  a one-way link). Busier links run faster; a link with no traffic has no
  comet. Under the system's *reduce motion* setting there is no motion,
  and the animation pauses while the tab is hidden. The editor, the PNG
  and a downloaded SVG stay still: the SVG only carries the invisible paths
  (`path.wm-flow`, with the load in `data-load`).
- **Pill link labels**: *Bandwidth labels* in the link panel (or *Label
  box* in *Link defaults* for every link) draws the label on a level box
  (`BWSTYLE classic`), a box along the link (`angled`) or a pill along the
  link with a small arrow toward where the traffic goes (`pill`), in the
  PNG and the SVG. The built-in fonts cannot turn in the PNG: pick a
  TrueType font for labels on links that are not level.
- **Links without arrow heads**: *Arrow heads* in the link panel (or in
  *Link defaults* for every link) draws the classic heads, small ones
  (`ARROWSTYLE compact`) or none (`ARROWSTYLE flat`): each half of the link
  ends square in the middle. With pill labels, the arrow on the label still
  shows which way the traffic goes, as on the Nine IX maps.
- **Badge nodes**: *Look* in the node panel draws the selected nodes as
  round badges (`SET node_style badge`): their icon, or the initials of
  their label, on a disc in the label background colour with a ring in the
  outline colour and a soft shadow, in the PNG and the SVG. The label is
  not drawn on the map: it shows on hover and in *Find a node*. Put the
  hint in `NODE DEFAULT` to make every node a badge (`SET node_style box`
  on a node keeps its box).
- **Sites and total traffic**: *Site* in the node panel (`SET site <name>`,
  offered from the sites already on the map) groups nodes in the side panel
  of the map view. Every link counts in the map's total traffic shown
  there; *Total traffic: Left out* in the link panel (`SET traffic_total 0`)
  leaves one out, for example a backbone link whose traffic is already
  counted on the access links. Both hints can go in `NODE DEFAULT` /
  `LINK DEFAULT`.
- **Themes**: *Apply the theme* in the *Background colour and theme*
  section of *Map properties*
  restyles the whole map in one click, saved at once and undone with
  Ctrl+Z. *Night* (an idea from the Nine IX traffic map): a dark canvas,
  links from cream to amber as the load grows, with the glow, pill link
  labels, dark node and link labels with light text, and the modern legend card with a gold edge.
  A theme only writes ordinary settings (colours, `KEYSTYLE`, the `SCALE`
  steps, the label colours of `NODE DEFAULT` and `LINK DEFAULT`); nodes and
  links with their own colours keep them.
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
  `DATA_ROOT/history/<map id>`, so they survive a reload. Each step keeps a
  fingerprint of the file it left: if the map was saved outside the history
  since then (rename, a copy on disk),
  that undo or redo is refused instead of overwriting the change, and the
  button says why. An undo sent from a tab that has not seen the latest save
  of another tab or user is refused too, and the map is reloaded.

The classic editor was removed: its address, `/editor/classic/<map id>`,
opens this editor.

### Icons

`objects/net/` holds a set of network icons (router, switches, firewall,
server, cloud, internet, Wi-Fi, load balancer, storage, workstation,
virtualisation host, site, VPN) as SVG with a PNG twin. Maps reference the
`.png` (or the `.svg`): the PNG output is drawn from the PNG, and the SVG
output and the editor use the vector file, so icons stay sharp when zoomed.
Any icon with an `.svg` next to it gets the same treatment, unless it is
coloured by `USEICONSCALE`.

### Backgrounds

The *Background* field of the map panel (shown when nothing is selected)
opens the background library: the images of `public/backgrounds` with a
preview, their size and how many maps use them. Type to search by name,
click one to put it under the map (`BACKGROUND backgrounds/<file>`), or
*(none)* to take it away. *Add backgrounds…* uploads PNG, JPEG or GIF
images (4096 px and 10 MB at most each) to `public/backgrounds`. The ×
on a background removes it; it only shows on backgrounds no map uses and
that did not come with NetworkWeathermapNG (the next deployment would bring
those back). In the Docker image the folder is in the data volume, so added
backgrounds survive a new container.

When the map has a background, *Background size* sets how it fills the
map (`SET background_sizing`, the PHP Weathermap 2.0 hint):

- **Natural size** (the default, `image`): the picture keeps its size in
  the top left corner, cut at the edges of the map;
- **Fit, keep the ratio** (`fit`): scaled to the map without distortion and
  centred; the map's background colour fills the rest;
- **Stretch to the map** (`stretch`): scaled to the whole map, its ratio may
  change.

The PNG, the SVG and the editor draw it the same way.

## Managing maps

Editors and administrators manage the maps from the **Maps** page (`/`),
the same page everybody uses to open them (the old Editor tab, `/editor`,
redirects there). They also see the inactive maps, greyed with a *Hidden*
badge. Hovering a thumbnail (always shown on touch screens) shows **Edit**
and a **⋯** menu; the list view has the same menu on each row:

- **New map**: empty (size of your choice) or a copy of an existing map.
- **Settings**: the *Map settings* panel, a drawer on the right (also the
  ⚙ button of the map view and *Map settings* in the editor's top bar),
  with every setting of the map on four tabs:
  - *General*: name, group, *Visible to users*, the `.conf` file and
    renaming it after the name, *Delete map…*. The name and the title drawn
    on the map are one thing: renaming a map changes its `TITLE`, and
    changing the title in the editor (or in its text mode) renames the map
    in the list. Maps made before this, whose name and drawn title differ,
    are listed at the top of the Maps page; their panel asks which one to
    keep (without a choice both stay as they are).
  - *Display & kiosk*: the map's time zone (type to search, empty for the
    default one), its zoom and display time in the kiosk.
  - *Recording*: record the map or not (while recording is allowed in
    Settings > Recording; switching it off deletes its images).
  - *Access*: who sees the map, read from the permissions: administrators
    and editors, the viewers of its group, viewers given the map itself.
  The Settings pages *Time zone*, *Kiosk* and *Recording* keep the
  defaults and the tables that set many maps at once.
- **Reorder**: drag a thumbnail by its grip (or focus the grip and use the
  arrow keys) to move it among the maps of its group; the order is the one
  of the Maps page and the kiosk for everybody.
- **Move to…**: another group, without leaving the page.
- **Hide from users** / **Show to users**: an inactive map is only shown to
  editors.
- **Duplicate**: choose the copy's name, group and visibility.
- **Download .conf** / **Export all** (page menu ⋯): download one
  configuration, or a zip of every map with its icons and backgrounds,
  which *Import maps* (page menu ⋯, Settings > Import maps) imports back.
- **Delete**: removes the map and everything kept for it: configuration and
  backup, rendered images and pages, link graphs, editor history,
  permissions and settings.
- In the list view, tick several maps to show, hide, move, export or delete
  them at once.

Administrators have a **Settings** space (`/admin`, opens on *Data
sources*). A side navigation (a row of chips on a phone) groups its pages,
with a counter next to the pages that list things:

- *Monitoring*: **Data sources** (one row per source with its state, *Test*
  on the row, ⋯ to edit or delete), **Import maps**.
- *Display*: **Maps & rendering** (image format and refresh interval, saved
  in the app; `.env` gives the defaults), **Kiosk**, **Recording**,
  **Time zone**.
- *Access*: **Users**, **Groups**.
- *System*: **Database check**.

Each page is a panel: its title and main action at the top, *Cancel* and
*Save* at the bottom right. Adding or editing a data source, a user or a
group opens its form in a panel on the right, with *Delete…* at the bottom
left; without JavaScript the same forms are pages of their own.

## Viewing maps

- **Maps** (`/`): the maps as thumbnails or as a list (the switch at the top right; your browser remembers the choice). The chips filter them: *All*, *Favourites*, one chip per group with its number of maps, and *Over 80 %* (maps whose most loaded link is at 80 % or more); the chosen chip is kept in the address (`?filter=favourites`, `?filter=busy`, `?group_id=2`). On phones the chips scroll sideways and the bottom bar has a *Favourites* entry. Type in the search box to filter maps by name (Enter opens the first match). The star on a card makes the map a favourite for your account; favourites come first. The badge on each card shows the state of the map: the colour and load of its most loaded link at the last render ("Max load 87 %" on hover). Maps without a recent render are refreshed in the background.
- **Map view** (`/map/{id}`): the map fills the page under the top bar and is drawn as SVG. Its controls float over it: a bar at the top (*Maps* › map name with a *Live* badge, *Find a node* (`/`), the *Live* / *Last 24 h* switch when the map is recorded, fullscreen (`F`), ⚙ *Map settings* and **Edit** (`E`) for editors, and a ⋯ menu: *Open as PNG*, *Graphs on hover (PNG)*, *Copy kiosk link*, *Download PNG*), the zoom at the bottom right (+, the zoom value (click for 100 %), −, fit) and the state at the bottom left (last update with the map's time zone, the most loaded link, and the colour legend of the map's scale). Zoom with the mouse wheel or a pinch, move by dragging, or use the keyboard (`+`, `-`, `0` fit, `1` actual size, arrows). "Find a node" centres the map on a node by label or name. Clicking a node (or finding it) zooms onto it and pins its card at the top right (a sheet at the bottom on a phone): its links, busiest first, with their IN and OUT load and the node at the other end (click one to go there), *Open its page* when the node has a URL, and, when some of its links read a data source, the same traffic graph for one of them (the busiest first, another one picked in the list above the graph); the rest of the map dims and the minimap rings the node, as it does for any node under the pointer. `Esc`, the × or a click on an empty part of the map closes the card. The address keeps the node (`#n=<name>`), so a shared link opens on it. The side panel (the panel button in the bar, or `P`; open by default on wide screens, then remembered in the browser) floats on the left: the nodes grouped by site (`SET site`, a plain list when the map has none) with the load of their busiest link, a click zooms onto a node and pins its card, the target button frames a whole site, and hovering a row highlights the node on the map and in the minimap while hovering a node on the map highlights its row; the map's total traffic (sum of the IN and OUT rates of the links that count, see *Sites and total traffic*) with, for a recorded map, a graph of the recorded hours (IN above the line, OUT below), its peak and 95th percentile; and the colour legend, one row per band. Bandwidth labels are hidden when they would be too small to read. A minimap shows where you are when the map does not fit. The zoom and position are kept in the address (`#z=2&x=..&y=..`): a shared link opens on the same view. Zooming never changes the saved map.
- Hovering a link shows its card: the link speed as a badge (its `BANDWIDTH`, `10G`), "A ↔ B" with the link name and the sites of its ends (`SET site`), the current IN and OUT rates with their load, and, when the link reads a data source (set up with the editor's picker, or an Observium port `TARGET`), its traffic over *1 day*, *1 week* or *1 month* with IN above the middle line and OUT below it, the peak and the average of the period. The graph is drawn with uPlot: the pointer on it shows the time and the IN and OUT values at that moment, dragging across it zooms on that part of the period (the peak and average then follow it), and a double-click or *Reset zoom* shows the whole period again. The period you pick is remembered in the browser; the card waits a moment when the pointer leaves the link so you can reach the switch. The series come from `/map/{id}/link/{linkId}/traffic?range=day|week|month` (at most 360 averaged points, cached 5 minutes; Zabbix sends its hourly trends for a week or a month). A link with an `OVERLIBGRAPH` shows that picture instead (about 40 % of the window width, 500 to 1400 px). Clicking a link opens its INFOURL in a new tab; a node opens its card, with *Open its page*.
- The view follows new data every `MAP_REFRESH_INTERVAL` seconds and right away after an edit, keeping the zoom. The SVG is cached next to the PNG (`DATA_ROOT/output/<map>.svg`, with the state summary in `<map>.json`) and drawn again in the same pass as the PNG.
- **History**: when a map is recorded (Settings > Recording: off for every map until switched on, and the admin can turn recording off for all), its viewer has a **Live** / **Last 24 h** switch (`H`; the hours follow the retention): a player goes through the frames of the last hours (play/pause with Space, a time slider, speed 1× to 10×, **Live** or Esc to come back), and the badge next to the name says *Playback*. A background recorder (`php bin/record.php`) draws each recorded map with live data every interval (5 minutes by default) and keeps the frames for the retention time (24 hours by default) in `DATA_ROOT/recordings/<map id>/`, about 1 to 20 KB each. The recorder only runs while at least one map is recorded: it exits as soon as no map is left (or recording is turned off), and the application starts it again when a map is switched on in Settings > Recording or when a recorded map is viewed. Only one recorder runs at a time. In the Docker image, supervisor also starts it at boot; it exits at once when there is nothing to record. Outside Docker, the same start on demand works as long as PHP may run `exec`; otherwise run `php bin/record.php --once` from cron every minute.
- **Fullscreen** (`F`) puts the map on the whole screen with the current zoom; the controls fade out after 3 seconds without movement and come back with the mouse, a touch or a key; Esc or `F` leaves it. *Copy kiosk link* copies the address of the fullscreen page (`/map/{id}?fullscreen=1`, with the current view) for a NOC wall: it opens fitted to the screen without the application bar. *Open as PNG* keeps the classic picture (also used for thumbnails, exports and the kiosk), with one *Fullscreen* (fitted) and *Preview* (traffic graph on hover).
- **Kiosk** (`/cycle`, or `/cycle?group=N` for one group): the maps one after the other, each for its own time (Settings > Kiosk). The map fills the page; a thin bar at the top fills up until the next map; the controls at the bottom (previous, pause, next, the map name and its rank, fullscreen) fade out after 3 seconds without movement. Keys: left and right arrows, Space to pause, `F` for fullscreen. `/cycle?fullscreen=1` opens it without the application bar.

## Command palette

`Ctrl+K` (`⌘K` on a Mac), or the search box of the top bar, opens one search
for everything, on every page of the application (in the editor it also
lists the editor's commands and the items of the map, see *Map editor*):

- **Maps** you can see, with their current load (hidden maps for editors).
- **Nodes** of every map, by label or name: opens the map zoomed onto the
  node, its card pinned (`/map/{id}#n=<name>`).
- **Settings** pages (administrators).
- **Commands**: *Edit* and *Map settings* for the maps found (and for the
  map you are on), *New map*, *Start kiosk* (for each group), favourite and
  busy maps, the theme, *Change password*, *Log out*.

Every word typed must appear, accents ignored. Up and Down move, `Enter`
opens (`Ctrl`/`Shift+Enter` in a new tab), `Esc` closes. The list comes from
`GET /palette`, fetched once per page.

## Language and theme

The user menu (the round button at the right of the top bar) has the
language, the theme (automatic, light, dark), *Change password* and *Log out*;
language and theme are remembered in cookies. Without a choice, the language comes
from `APP_LOCALE`, then the browser, else English. Translations live in
`src/app/Lang/<locale>/*.php`, one file per part of the application, as
`'English text' => 'translation'`; `*-js.php` files hold the texts of a
browser script and `_meta.php` gives the language name. Every folder there
appears in the language menu. In code, wrap texts with `__('…')` (or
`e__('…')` to print them escaped) and add them to the catalogues;
`php src/bin/i18n-check.php` lists missing and unused keys.

To add or improve a language, see [TRANSLATING.md](TRANSLATING.md).

Colours, spacing and type come from one file of design tokens,
`src/public/assets/css/tokens.css`, for both themes; `tests/App/DesignTokensTest.php`
checks their contrast (text 4.5:1, field edges and focus 3:1). Interface icons
are a Lucide subset in `src/public/assets/icons/ui.svg`, printed with
`icon('name')`. Pages load styles and scripts with `asset('/assets/…')`,
which adds a version taken from the file: `/assets` is cached for 30 days, so
a changed file gets a new address. The shell's toasts and confirmation dialog
are `WMUI.toast(message, type)` and `WMUI.confirm({message, confirm, danger})`
(`src/public/assets/js/ui.js`, styles in `ui.css`); a link with
`data-method="post" data-confirm="…"` asks through that dialog. Error pages
(403, 404, 419, 500) are drawn in the shell by `App\Core\ErrorPage`. The redesign plan is in
[docs/studies/ui-redesign.md](docs/studies/ui-redesign.md).

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
│   ├── examples/maps/     # Demo maps (bin/demo-maps.php)
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
**Settings → Import maps**: upload one or more `.conf` files, or a `.zip` holding
the `.conf` files with their icons and backgrounds.

For each map the review page shows:

- **Checks**: `INCLUDE` lines are removed (they read files on the old server),
  the old `*OUTPUTFILE` / `IMAGEURI` settings are ignored, engine warnings.
  Maps saved by PHP Weathermap 2.0 read without warnings: their notes are
  drawn (see Map notes), `SET traffic_pulse` works (see Saturated links)
  and `COMMENTCURVE 1` makes comments follow the curve (see Comments along
  curved links) and `SET background_sizing` sizes the background (see
  Backgrounds) and `SET editor_label_position` places node labels (see
  Labels in the editor). A 2.0 map that does not set `COMMENTCURVE` keeps straight
  comments here (2.0 curves them by default): add `COMMENTCURVE 1` to
  `LINK DEFAULT` to curve them all.
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
