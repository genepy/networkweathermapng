# Study: a fresh map style inspired by Nine IX

Reference: https://stats.nine-ix.net/ (screenshots shared by the user on
2026-11-03, dark and light theme, zoomed Paris view; site visited the same
day, version 1.8.11). Status: tasks 1 (Night theme) and 2 (pill labels)
done, tasks 3 to 8 to do, listed in [ROADMAP.md](../ROADMAP.md). Ideas only: copy no code, logo
or artwork; credit the idea in THIRD-PARTY-NOTICES.md ("Ideas from other
projects") when a piece ships.

## What makes it look modern

1. **Dark geographic basemap**: muted dark vector tiles (OpenStreetMap /
   OpenMapTiles), place names in low-contrast grey. The network floats on it.
2. **Links as thick, rounded strokes** in a warm cream-to-amber family, with a
   soft glow. Idle or backup links are grey and curved; busy ones get warmer.
3. **Rate labels as small dark pills** placed mid-link and rotated with the
   link (`23.20k`, `96.79G`), with a tiny arrowhead showing the direction.
4. **Round node badges** (logo in a glossy yellow disc, light drop shadow)
   instead of boxes with text; names live in the side panel and the popup.
5. **Hover popup card**: speed badge (`400GbE`), "A ↔ B" title, sites under
   it, then an IN/OUT area graph (in above zero, out below) over 3 days.
6. **Floating translucent panels** with rounded corners and a thin gold
   border: Points of Presence (sites with member counts), Total traffic
   (Total / Peering / Circuits tabs, 1 day / 1 week / 1 month, 95th and peak),
   a city inset map (Paris, Amsterdam) top right, a slim gradient legend
   bottom right, a theme toggle at the top.

7. **Animated links**: a small glowing comet (a short gradient blob, oriented
   on the tangent, so it follows curves) travels along every link, about
   0.4 trip per second, in the traffic direction; hovering a link fades a
   halo in and out around it.
8. **Site focus**: hovering a site (or a cluster of sites) shows its city in
   the top right inset, a real mini map of that city with its sites and
   links (Paris, Amsterdam...; hovering the inset title picks another city).
   Hovering also highlights the site in the side list. Clicking a site
   flies the main map to it (zoom 11) and pins its detail panel; the
   zoomed view shows the streets of Paris with the metro links.
9. **Clusters**: sites too close to tell apart at the current zoom merge
   into one badge; clicking it zooms to fit them and they split.
10. **Light theme**: same layout on a light beige basemap, links in
   gold/brown, white panels. The theme button switches the basemap style too.

How it is built (read for ideas only, their code carries no licence, copy
nothing): MapLibre GL JS (BSD-3) draws vector tiles from OpenFreeMap (free,
no key) with the OpenMapTiles *Dark Matter* and *Positron* styles (BSD-3);
sites are placed by latitude/longitude; links are two line layers (glow +
core, round caps) coloured by utilisation; the moving comets are drawn on a
canvas over the map with `requestAnimationFrame`; rate pills, site badges
and the inset are HTML markers. It has no `prefers-reduced-motion` handling.

## What we already have

Zoomable SVG viewer with minimap, find a node, history playback (#48);
hover popup with graph (`WM.hover`); dark theme toggle (cookie,
`data-theme`); modern legend card (#47); saturated link pulse (#54); curved
comments (#55); colour scale presets (#53); shipped fonts (#46).

## What to borrow, as small tasks (suggested order)

1. (done) **"Night" map theme preset** (Map properties): dark background colour,
   cream→amber colour scale preset, rounded line caps, soft glow on links
   in SVG (filter), gold-bordered translucent legend and title card. Pure
   styling of what exists: biggest visual win for the least code. PNG gets
   the colours and caps; the glow is SVG only.
   Built as `MapThemes` (op `apply_theme`, one undo step) and
   `SET link_glow 1`. Not done: round caps (our links are filled arrows,
   not strokes) and translucent cards (map colours are opaque); the legend
   is the opaque modern card with a gold edge.
2. (done) **Pill link labels**: a `bwlabel` style "pill" (dark rounded box, light
   text, rotated along the link, small direction arrow). Same idea as
   `COMMENTCURVE`, in PNG and SVG; a 2.0-style `SET` hint name if one exists.
   Built as `BWSTYLE pill` (2.0 has no such hint; `BWSTYLE` already takes
   `classic|angled`), drawn by `WeatherMap::DrawLabelPill`. Built-in GD
   fonts cannot turn in the PNG, so use a TrueType font for links that are
   not level.
3. **Badge nodes**: a node style "badge" (disc with icon or initials, ring,
   shadow), label hidden on the map and shown in the popup / panel. Can reuse
   the icon library (#39).
4. **Richer hover card**: link speed badge from `BANDWIDTH`, "A ↔ B" title
   from node names, IN above / OUT below graph, and 1 day / 1 week / 1 month
   switch when the data source gives graphs for those ranges.
5. **Viewer side panels** (SVG viewer, optional, remembered per browser):
   site list (nodes grouped by a new optional `SET` group/site field, click
   to zoom like *Find a node*), total traffic of the map (sum of chosen
   links, from the recorder #48 data), legend moved into a floating card.
6. **Geographic basemap (largest, last)**: optional map setting with a tile
   URL (self-hosted or OSM-compatible, attribution shown), nodes placed by
   latitude/longitude, the SVG viewer drawing tiles under the map (e.g.
   MapLibre GL or Leaflet, MIT/BSD). Needs: a projection between lat/lon and
   map pixels, editor support to pick a position on the map, a fallback to
   the plain background for PNG and offline sites. Write a short design note
   before coding, because it changes the map format. MapLibre GL + OpenFreeMap
   + the OpenMapTiles Dark Matter / Positron styles is the proven stack;
   self-hosted tiles must stay possible for closed networks.
7. **Link flow animation** (SVG viewer, can come right after 1): a glowing
   dot moving along each link in the IN and OUT directions, speed or size
   tied to the load, per-map switch next to the pulse (#54), none under
   `prefers-reduced-motion`, paused while the tab is hidden. Works on our
   current maps without the basemap (SVG `getPointAtLength` on the link path).
8. **Site focus and zoom** (viewer): click a node to animate the zoom onto it
   (we already have `animateTo`) and pin its card; hover a node to highlight
   it in the site list. With the basemap, add the city inset (hover shows the
   site's city in the top right card) and the merging of close sites.
   Without the basemap, the existing minimap can play this part: hovering a
   node or a site in the list frames it in the minimap.

Rules for every piece: English code, UI strings via translation files with
French added, one task per PR, README and docs/ROADMAP.md updated, review
and merge in a separate thread.
