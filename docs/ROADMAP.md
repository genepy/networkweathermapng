# Roadmap

Backlog and status board shared by every agent. Read
[AGENTS.md](../AGENTS.md) first: it explains how to claim an item, keep its
line up to date and hand it over.

States: *Not started*, *In progress*, *Paused*, *Done*. One line per item:
an item with a branch belongs to whoever owns that branch.

Last updated: 2026-11-12.

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

Editor and viewer feedback of 2026-11-06, in order. Every point is in
[studies/feedback-2026-11-06.md](studies/feedback-2026-11-06.md), with
the open question P8.

| Item | Notes |
|------|-------|
| F4. Link labels in the editor | *Untangle* in the selection bar, limited to the selection, and the same for nodes; untangle leaves overlaps (better algorithm); labels on/off per link and in Link defaults; drag a label with the mouse; links without arrow heads, arrow on the pill label (Nine IX). [feedback-2026-11-06](studies/feedback-2026-11-06.md) P1-P3, P20, P23, P24 |
| F5. Link ends and hover highlight | Link ends always hidden under their node; back to a straight segment after right angles; link glow only on hover; every element outlined with a soft glow on hover (editor and viewer). [feedback-2026-11-06](studies/feedback-2026-11-06.md) P17, P26, P31, P35 |
| F6. Editor navigation and shortcuts | `Ctrl` + drag on the background pans (duplicate moves to `Alt` + drag, proposal to confirm); keyboard shortcuts page in the user profile (rebind, conflicts, reset). [feedback-2026-11-06](studies/feedback-2026-11-06.md) P9, P25 |
| F7. SVG everywhere in viewer and kiosk | Preview and kiosk drawn as SVG, PNG only for download; merge View and Preview; kiosk link keeps the background; *Download as SVG*; collapse/expand all sites in the side panel; popups close on an outside click. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P29, P33, P34, P36-P38 |
| F8. Boxes on the map | Movable boxes on the map, in the `.conf`, shown in live, kiosk, PNG and SVG: total traffic, traffic legend (Nine-style gradient bar), free formatted box (like Nine's Points of presence), link speed, updated at. After F7. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P30, P32 |
| F9. Map settings and colour picker in the editor | *Map settings* in the editor's right panel instead of another page; a colour picker in the app's style. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P13, P18 |
| F10. Themes and load gradient | More coherent map themes in the library; a gradient link load scale like Nine IX. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P19, P28 |
| F11. Fonts | Modern open-source default font that follows curved links; every shipped font for nodes, legends, zones; map-wide font with confirmation, per-object override kept. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P16, P27 |
| F12. Zone shapes and labels | Rounded corners and other shapes for zones; move a zone's label with the mouse. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P15 |
| F13. Savepoints and timestamped history | VS Code Timeline style list of saved versions and named savepoints, preview and restore (builds on `ConfigHistory`). [feedback-2026-11-06](studies/feedback-2026-11-06.md) P12 |
| F14. Focus areas | Highlight parts of a map, also in kiosk mode, saved in the `.conf`. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P22 |
| F15. Geographic maps in the editor | Zoom in the editor like the viewer; cluster modes (nearby, none, author-defined groups); per-site size that stays proportional with zoom and matches the viewer. [feedback-2026-11-06](studies/feedback-2026-11-06.md) P10 |

## Stale branches

| Branch | Note |
|--------|------|
| `claude/project-thread-9dr755` | "Build the testbed images with the classic Compose builder": superseded by #20 (host-network entitlement). Can be deleted |

## Recently done

| PR | Item |
|----|------|
| #87 | F3. Map items in the editor: *Map items* in the Add panel shows the *Link load* legend, the *Link speed* legend (`SET width_legend`) or the *Timestamp* in the middle of the view; Delete (key, selection bar, right panel, context menu, Ctrl+K) hides a selected legend or timestamp, one undo step (`hide_furniture` / `show_furniture` editor ops; hidden legends and timestamps keep their place as -x - 1, -y - 1, the engine no longer draws a timestamp at a negative position); *Link speed legend* section in the map panel (shown, text) and *Show the timestamp on the map*. P8 was a copy of P7 |
| #86 | F2. Admin pages polish: *Compare old and new* / *Convert again* also at the top of the import review page; *Cancel* / *Save* also at the top of Kiosk mode, and the *Cancel* / *Save* bar of every Settings form stays at the bottom of the screen on a long page (sticky); denser admin pages (density tokens `--wm-row-pad-y`, `--wm-cell-pad-x`, `--wm-field-gap`, `--wm-panel-pad` in tokens.css, larger with `pointer: coarse`); *Maps & rendering* (image format, refresh interval) saved in the settings table (`RenderingSettings`, applied to `Config` at start-up with `Config::override()`; `.env` gives the defaults). Test `RenderingSettingsTest` |
| #85 | F1. Editor quick fixes: the save state of the top bar keeps one width (every label laid out hidden in the same cell); the folded row of a Map properties section (*Legend* Shown / Hidden...) follows a change at once; the snap grid is light on a dark background colour, dark on a light one, dark with a light halo over a picture or basemap (`data-grid-tone` on `#stage`); the engine shapes in the icon picker (*(none)*, *Box*, *Rounded box*, *Circle*) are drawn in inline SVG. Test `EditorSaveStateTest` |
| #83 | Map pictures (PNG, preview, thumbnails) render from the web root, so `fonts/` and `objects/` paths resolve under PHP-FPM, and the engine warnings are logged instead of written to the standard error, which broke the picture of the demo maps |
| #82 | Viewer: deep in a basemap, links keep their width at 100 % (arrows carry `data-spine`, the viewer redraws them), the SVG covers only the view past 4096 px (flickering seams), *Play them back* link in Map settings > Recording |
| #81 | Demo maps: three maps in `src/examples/maps` drawn from static values (*France sites* on a basemap with several sites per city, clusters and city inset, badges; *Datacenter fabric* leaf/spine with vector icons and zones; *ISP backbone* with transit and peering in the Night theme colours). `bin/demo-maps.php` (`App\Services\DemoMaps`) and the testbed seed add them once to a *Demo maps* group; test `DemoMapsTest` |
| #80 | UI redesign, step 11: the classic editor is removed (`EditorController`, `editor/visual.php`, `editor/edit.php`, `editor.css`, `editor.inc.php`, its routes, its strings and its entry in the editor's ⋯ menu); `/editor/classic/{id}` opens the SVG editor, for bookmarks. What only it could do moved to the SVG editor: a link's *Info URL* (`INFOURL`), a *Hover graph* on nodes and links (`OVERLIBGRAPH`, addresses separated by spaces, `http(s)` or local paths), a separate out bandwidth (*Out, if different*, `BANDWIDTH 1G 100M`) and *Tidy ends* (the link starts and ends on the facing sides of its nodes, links between the same two nodes spread apart) |
| #79 | UI redesign, step 10: the traffic graph of the hover card is drawn with uPlot 1.6.32 (`assets/vendor/uplot`, loaded the first time a graph is drawn, from the `data-uplot` attributes of the `weathermap.js` tag; the plain SVG graph stays as a fallback): the pointer on it shows the time and the IN and OUT values, dragging across it zooms on a part of the period (peak and average follow), double-click or *Reset zoom* goes back; colours follow the theme (redrawn when it changes). The node card shows the same graph for one of its links that has a history (busiest first, picked in a list). `WM.trafficPanel(box, {width, changed})` in weathermap.js; test `TrafficChartAssetsTest` |
| #78 | UI redesign, step 9: editor, part 2: no permanent toolbar by default; a bar floats over the selection with what applies to it (*Align and space evenly* menu, *Group* / *Ungroup*, *Lock* / *Unlock*, *Front*, *Back*, *Link* the two selected nodes or from the selected one, *Duplicate*, *Delete*, ⋯ for the menu of actions), a small rail top left of the drawing (*Select*, *Link*, *Grid*) and the zoom bottom right next to the overview map. The movable toolbars stay available: ⋯ › *Toolbars and panels* › *Movable toolbars* (`mode` in `localStorage wm-editor-layout`). The `Ctrl+K` search is in the editor's top bar: the items of the map (Enter selects and zooms onto one), every editor command that applies now with its shortcut (selection first), then the application-wide results. `WMPalette.register(source)` lets a page add its own sections (`editor-palette.js`); the bar is `editor-selbar.js` |
| #77 | UI redesign, step 8: editor, part 1: a top bar (back, map name, *Saving… / Saved / Not saved*, undo and redo; *Layers*, *Text mode*, a ⋯ menu with *Map properties*, *Map settings*, *Toolbars and panels*, *Keyboard shortcuts*, theme, new tab and classic editor; *View*); the *Add* panel with four tiles (*Node*, *Note*, *Zone*, *Link* `L`; click adds in the middle of the view, drag still works), icons that add a node on click, and a *Backgrounds* fold that sets the map background; the *Map properties* dialog is gone: with nothing selected the right panel shows the map with sections that fold and show their value (background colour and theme, title, legend, link colours, timestamp, link defaults, basemap, fonts), each field saved when it changes (one undo step), the colour scale with *Apply the colours*; a selected legend, title or timestamp shows its section; `?` opens every keyboard shortcut. The *History* toolbar is gone (undo and redo are in the top bar) |
| #76 | UI redesign, step 7: command palette: `Ctrl+K` (`⌘K`) or the search box of the top bar opens one search on every page of the shell (Maps, viewer, Settings): maps with their load, nodes of every map (opens the map zoomed onto the node, `#n=`), Settings pages (admins) and commands (*Edit* / *Map settings* for the maps found and the current map, *New map*, *Start kiosk* for each group, favourite and busy maps, theme, *Change password*, *Log out*). Every word must match, accents ignored; arrows, Enter, Ctrl/Shift+Enter for a new tab, Esc. Index from `GET /palette` (`App\Services\CommandPalette`), script `assets/js/palette.js` |
| #75 | UI redesign, step 6: Settings: a side navigation in four groups (*Monitoring*: Data sources, Import maps; *Display*: Maps & rendering, Kiosk, Recording, Time zone; *Access*: Users, Groups; *System*: Database check) with counters, chips on a phone; `/admin` opens on Data sources. Each page is a panel (title and main action at the top, *Cancel* and *Save* at the bottom right). Data sources: one row per source with a state dot, type, URL and "tested … ago", *Test* on the row (result and request/response on the row) and a ⋯ menu; the forms of data sources, users and groups open in a drawer (`WMUI.drawer`, ui.js) with *Delete…* on the left, the chosen data source type shows only its fields. *Maps & rendering* shows the `.env` values read only (its old form posted to a missing route) |
| #74 | UI redesign, step 5: Map settings panel: every setting of a map in one drawer (⋯ › *Settings* on the Maps page, ⚙ in the viewer; a page of its own from the editor's *Map settings*), tabs *General* (name = drawn title, group, visible to users, file, delete), *Display & kiosk* (time zone with search, kiosk zoom and display time), *Recording*, *Access* (who sees the map, read only). Maps whose name and drawn title still differ are listed on the Maps page and their panel asks which one to keep. Editors reorder the thumbnails of a group by drag and drop (or the arrow keys on the grip) |
| #73 | Nine IX style, task 4: richer hover card: a link's card shows its speed badge (`BANDWIDTH`), "A ↔ B" with the link name and the sites of its ends, IN and OUT now with load bars, and its traffic over 1 day, 1 week or 1 month (switch remembered in the browser) drawn in the browser with IN above the middle line and OUT below, with peak and average; series from `/map/{id}/link/{linkId}/traffic` (bucketed by `TrafficSeries`, cached 5 minutes, Zabbix trends for a week or a month). The card follows the theme, and waits a moment when the pointer leaves the link so the switch can be reached |
| #72 | Nine IX style, task 8, part 2: city inset and clusters (viewer, maps with a basemap): located nodes keep their 100 % size when zooming in; nodes too close at the current zoom merge into a numbered badge (ring coloured by their busiest link), a click zooms until they split or lists nodes on the same point; hovering a site, a cluster or a side panel row shows its city in a small web map top right (located nodes and links), the focused node's city stays; bandwidth labels hidden above 40 px on a basemap. The summary gives `geo` for located nodes |
| #71 | Nine IX style, task 6: geographic basemap: a web map under the map in the viewer and the SVG editor (MapLibre GL JS shipped, loaded only for these maps), with the canvas placed by `SET basemap_center` and `SET basemap_zoom`, style or raster tiles from `SET basemap` (+ `basemap_dark` for the dark theme), nodes on their point with `SET geo <lat> <lon>`; tile servers allowed by `BASEMAP_SERVERS` (default OpenFreeMap) and added to the CSP; *Map properties > Basemap* and *Location* in the node panel. Design note: [docs/studies/geographic-basemap.md](studies/geographic-basemap.md) |
| #70 | Nine IX style, task 5: viewer side panels: a floating panel on the left of the map view (button or `P`, open by default on wide screens, remembered in the browser) with the nodes grouped by site (`SET site`, *Site* in the node panel) and the load of their busiest link, the map's total traffic (links count unless `SET traffic_total 0`, *Total traffic* in the link panel) with a graph of the recorded hours, peak and 95th percentile (`/map/{id}/totals`, cached in `totals.json` next to the frames), and the colour legend. Hovering a node on the map highlights its row; hovering a row highlights the node and frames it in the minimap; a click zooms onto it, the target button frames a site |
| #69 | Nine IX style, task 8: site focus and zoom: clicking a node (or finding it) zooms onto it and pins its card (its links busiest first with IN/OUT load, the node at the other end, one click away; *Open its page* for a node with a URL), the rest of the map dims, the minimap rings the node (also on hover); `#n=<node>` in the address, Esc or a click on the map closes the card. Link items of the map summary name their ends (`a`, `b`) |
| #68 | Nine IX style, task 7: link flow animation: `SET link_flow 1` makes small glowing comets run along each link in the viewer, from where the traffic leaves to the arrow head, faster on busier links; switch *Traffic flow* in Map properties > Link colours; none under `prefers-reduced-motion`, paused while the tab is hidden; the SVG only carries invisible paths, so the editor, the PNG and a downloaded SVG stay still |
| #67 | UI redesign, step 4: map viewer: the map fills the page with floating controls (bar with *Find a node*, *Live* / *Last 24 h*, one fullscreen with the browser's Fullscreen API keeping the zoom, ⚙ *Map settings*, ⋯ *Open as PNG* / *Copy kiosk link* / *Download PNG*, *Edit*), zoom at the bottom right, state and colour legend at the bottom left (`scale` in the map summary), controls that fade out in fullscreen; kiosk with a progress bar, floating controls that fade out, arrows, Space and `F` |
| #66 | UI redesign, step 3: one Maps page: thumbnails and list (remembered), filter chips (*All*, *Favourites*, each group, *Over 80 %*), *Edit* and a ⋯ menu on hover (settings, duplicate, move, hide, preview, .conf, delete), bulk actions in the list, inactive maps greyed for editors; `/editor` redirects to `/`; *Favourites* in the phone bottom bar; a title edit in the editor renames the map (`MapService::followTitle`) |
| #65 | Nine IX style, task 3: badge nodes: `SET node_style badge` draws a node as a round badge (its icon, or the initials of its label, on a disc with a ring and a soft shadow) in PNG and SVG; the label becomes the tooltip; *Look* in the node panel |
| #60 | UI redesign, step 2: app shell: thin top bar, user menu (language, theme, password, log out), no footer, bottom navigation on phones, toasts (`WMUI.toast`), a styled confirmation dialog (`WMUI.confirm`), error pages in the shell (`ErrorPage`), versioned asset addresses (`asset()`) |
| #64 | Nine IX style, task 2: pill link labels: `BWSTYLE pill` draws the bandwidth label on a rounded box along the link with a small arrow toward where the traffic goes, in PNG and SVG; per link in the inspector, for every link in *Link defaults*, and in the *Night* theme |
| #63 | Refuse undo after an outside change: each undo step keeps a fingerprint of the file it left (`ConfigHistory`), so an undo or redo is refused when the map was saved outside the history since; an undo from a tab that missed another save is refused too (study item 9) |
| #62 | Nine IX style, task 1: *Night* map theme, applied in one click from *Map properties* (dark canvas, cream to amber link scale, dark labels, gold-edged modern legend), and a per-map link glow in the SVG (`SET link_glow 1`) |
| #61 | Cacti plugin study ([docs/studies/cacti-plugin.md](studies/cacti-plugin.md)): stay standalone and read Cacti as a data source (database and RRD backends), convert Cacti targets and the plugin's map list on import, a thin bridge plugin only if asked; a native plugin is not recommended (name collisions with the Weathermap plugin, Cacti's `__()`, rewrite of the app shell). Next items are left to the maintainer |
| #59 | UI redesign, step 1: design tokens in `tokens.css` for both themes, contrast-checked colours (WCAG 2.2 AA, `DesignTokensTest`), field and control edges at 3:1, a Lucide icon sprite (`icon('name')`) instead of emojis; no layout change |
| #58 | Node label position presets: *Label position* in the node panel puts the label above, at the top, centre, bottom or below the icon in one click (`SET editor_label_position`, the 2.0 hint, now drawn on import too) |
| #57 | Background fit or stretch: *Background size* in the map panel draws the background at its natural size, fitted to the map (ratio kept, centred) or stretched, in PNG, SVG and the editor (`SET background_sizing image\|fit\|stretch`, the 2.0 hint, so imported maps use it) |
| #56 | Background library: the map panel's *Background* field opens a dialog to search the backgrounds (preview, size, maps that use it), pick one, upload PNG, JPEG or GIF images and remove the ones no map uses (shipped backgrounds are kept) |
| #55 | Comments along curved links: `COMMENTCURVE 1` draws IN/OUT comments along the curve in PNG (glyph by glyph) and SVG (text path), straight where the link is straight, bends too much, or the font is a bitmap one; *Along the link* in the editor's link panel; imported 2.0 maps keep their explicit setting |
| #54 | Highlight saturated links: links at 80 % or more pulse and glow in the viewer, per-map switch in Map properties > Link colours (`SET traffic_pulse 1`), no pulse under `prefers-reduced-motion` |
| #53 | Colour scale editor with presets: a Link colours tab in Map properties edits the DEFAULT scale step by step, with a preview bar and five presets (classic, traffic light, colour-blind safe, heat, blue) |
| #51 | Map notes: free text on several lines, left, centred or right, drawn sharp in PNG and SVG; added and edited in the SVG editor (Note button, inspector); same format as PHP Weathermap 2.0 (`SET editor_note`), so imported notes are drawn |
| #50 | Import PHP Weathermap 2.0 maps: `COMMENTCURVE` and the 2.0 hints (notes, pulse, background sizing, label position) read without warnings, are kept in the converted map and listed on the review page as not drawn yet |
| #49 | Time zone in the remaining previews: the old PNG editor preview and the import compare view draw the timestamp in the map's zone (the default zone for an import not yet turned into a map) |
| #48 | Map recording and playback: a background recorder draws opted-in maps every interval (Admin > Map recording, off by default, can be turned off for all), the viewer plays the last hours back |
| #47 | A modern legend style (rounded card, colour bar, 0-100% marks) and a rounded label for the timestamps, in PNG and SVG, chosen in Map properties |
| #46 | Fira Sans, Barlow Semi Condensed and IBM Plex Mono shipped (OFL); a Fonts tab in Map properties adds them to a map, and font lists show names instead of numbers |
| #45 | Editor: custom layers: name a set of nodes, links and zones (saved in the map), then hide it in the editor, lock it or select it from the Layers panel |
| #39 | Editor: pick a node's icon from a searchable library in the properties panel; the palette search matches several words and theme names |
| #38 | Editor: resize node icons with a corner handle or width/height fields (ICON w h file), shapes get a size |
| #43 | Editor: route the selected links with right angles (across first, down first, or two turns half way), from the link panel or the menu |
| #42 | Editor: zones resize by any side (one dimension) or corner (Shift keeps proportions), with the alignment guides |
| #41 | Editor: the Zone button shows the new zone under the pointer, a click drops it, Esc cancels |
| #40 | Links up to 800G: auto width table goes to 800G (15 px), 200G/800G in the editor suggestions, wide links on short spans get shorter arrowheads instead of vanishing |
| #44 | Editor: the Layers panel explains that hiding a layer only changes your view, is not saved in the map and is remembered by this browser |
| #37 | Editor: Enter applies a field and leaves it (the panel follows a rename), Enter in the dialogs applies instead of cancelling |
| #36 | Editor: the properties panel follows the selection again after editing a field (a click on the map now leaves the field and saves it) |
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
