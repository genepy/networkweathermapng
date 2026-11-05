# Design note: geographic basemap

Nine IX style, task 6 (see [nine-ix-style.md](nine-ix-style.md)). Written
2026-11-05 before the code, because it adds to the map format.

## Goal

A map can float on a real web map (streets, coasts, place names), as on
stats.nine-ix.net: the tiles are drawn under the SVG map in the viewer and in
the editor, they move and zoom with it, and nodes can be placed by latitude
and longitude. Everything else keeps working as before: the PNG image, the
downloaded SVG, the minimap, history playback, kiosk, maps without a
basemap.

## Map format

Map-level hints (our own names; PHP Weathermap 2.0 has nothing similar):

```
SET basemap https://tiles.openfreemap.org/styles/positron
SET basemap_dark https://tiles.openfreemap.org/styles/dark
SET basemap_center 46.6 2.4
SET basemap_zoom 5
SET basemap_attribution © My tile server
```

- `basemap`: a MapLibre style (JSON URL), or raster tiles as an XYZ
  template with `{z}`, `{x}`, `{y}` (256-pixel tiles, as OpenStreetMap or a
  self-hosted tile server gives them). No basemap without it.
- `basemap_dark` (optional): the same for the dark theme, of the same kind.
  The viewer switches when the theme changes.
- `basemap_center` and `basemap_zoom`: the frame. The point at the centre
  of the canvas, and the web map zoom level at which one map pixel is one
  screen pixel at 100 %. Zoom levels count 512-pixel world tiles, as
  MapLibre does (the world is `512 × 2^zoom` pixels wide).
- `basemap_attribution` (optional): the credit shown on the map. Styles
  carry their own; raster tiles default to "© OpenStreetMap contributors".

Node hint:

```
NODE paris
    POSITION 398 147
    SET geo 48.8566 2.3522
```

- `geo <lat> <lon>`: the node sits on that point. The engine computes its
  position when it reads the map (before relative positions, so nodes
  placed relative to it follow). `POSITION` is still written: it is what
  the PNG uses and what tools that do not know `geo` read. A node without
  `geo` keeps its pixel position, so titles, legends, notes and free
  diagram parts can stay put on the canvas.

### Why a frame and not a free geographic map

The map stays a fixed-size canvas in pixels. That keeps the engine, the PNG,
the editor geometry, link vias, zones, curved comments and every existing
map unchanged: the basemap is only a picture under the canvas, placed by a
projection. The cost is that nodes, labels and links grow with the zoom
(as they already do in the viewer) instead of keeping a constant size on a
zoomed web map. Constant-size markers would need a different renderer and
are left out.

## Projection

Web Mercator, as every tile server uses:

```
world(lat, lon) = ((lon + 180) / 360, 1/2 − ln((1 + sin φ) / (1 − sin φ)) / 4π)
x = width / 2  + (world(p).x − world(centre).x) × 512 × 2^zoom
y = height / 2 + (world(p).y − world(centre).y) × 512 × 2^zoom
```

PHP: `WeatherMapGeo` in `src/lib/WeatherMap/geo.php`. JavaScript: the same
formulas in `src/public/assets/js/basemap.js`.

## Drawing the tiles

[MapLibre GL JS](https://maplibre.org/) 5.24.0 (BSD-3-Clause), shipped in
`src/public/assets/vendor/maplibre-gl/` (CSP build: its worker is a file of
the site, no `blob:` worker), loaded only on pages whose map has a basemap.
It draws vector styles and raster tiles alike. MapLibre never handles the
mouse (`interactive: false`): the viewer and the editor keep their own pan
and zoom, and on every change they tell the basemap where the canvas is
(`sync(z, tx, ty)` → `jumpTo({center, zoom: basemap_zoom + log2(z)})`).
With a basemap, the viewer zooms in up to 64× (six more zoom levels).

Once the tiles are drawn, the map's background rectangle becomes
transparent (the background picture, if any, is still drawn). If WebGL or
the style is not available, the map keeps its plain background.

## Tile servers and privacy

Tiles are fetched by the visitor's browser. The default
Content-Security-Policy only allows the site itself, so the servers a
basemap may use are listed in `BASEMAP_SERVERS` (space-separated URL
prefixes, default `https://tiles.openfreemap.org`). Their origins are added
to `img-src` and `connect-src`; the editor refuses any other address. A
basemap served by the site itself (`/tiles/...`) is always allowed, which is
how a closed network uses a self-hosted tile server behind the same nginx.
A site that sets its own `APP_CSP` adds the tile servers itself.

[OpenFreeMap](https://openfreemap.org/) gives OpenStreetMap vector tiles
for free without a key; its Positron and Dark (OpenMapTiles styles, BSD-3)
and Liberty styles are the presets of the editor. The data is © OpenStreetMap
contributors (ODbL), credited by the style.

## Editor

- *Map properties > Basemap*: presets (OpenFreeMap light/dark, Liberty,
  none), style or tiles address, dark address, centre, zoom, credit, and
  *Frame the located nodes* (centre and zoom that fit every located node).
- Node panel, *Location* (only on a map with a basemap): latitude and
  longitude, *Locate where it is*, *Remove the location*.
- A node added on a basemap is located where it is dropped (notes are not).
  Moving, cloning or pasting a located node moves its point.
- The tiles are drawn under the canvas, the paper becomes a dashed frame;
  the *Background* layer switch hides them too.

## Left for later

- ~~The rest of task 8 that needs the basemap~~: done, see *Viewer: clusters
  and city inset* below.
- Constant-width links at deep zoom levels (located nodes keep their size,
  links still grow with the zoom).
- Picking the frame by dragging the web map, and a place search (needs a
  geocoding service).
- Drawing the tiles into the PNG on the server (offline renders).
- An admin page for the allowed tile servers (only `BASEMAP_SERVERS` now).

## Viewer: clusters and city inset

Nine IX style, task 8, part 2. Only in the viewer, only for maps with a
basemap; the summary gives each located node its point (`geo: [lat, lon]`).

- Located nodes keep, past 100 %, the size they have at 100 %: a CSS scale
  of `1 / zoom` around their point (`--wmv-geo-k`). Zooming in thus pulls
  close sites apart instead of growing them together.
- Two located nodes closer on the screen than 32 pixels (or than 80 % of the
  smaller one) merge; the focused node never does. The cluster is an HTML
  badge over the map with the number of nodes, its ring coloured by the load
  of their busiest link. A click zooms so they fit and the closest two
  split; nodes on the same point cannot split and are listed in a small menu
  (a click focuses one).
- The city inset is a second, small MapLibre map (made on the first hover),
  framed on the hovered nodes (zoom 11 at most) with every located node as a
  dot and every link between two located nodes as a line of its colour.
  Hovering a node shows its whole site (`SET site`); hovering a cluster or a
  site row shows those nodes. Leaving them goes back, after a moment, to the
  focused node's city, or hides the inset. It sits under the bar on the
  right, beside the node card, and is left out on phones.
- Deep in a basemap, bandwidth labels larger than 40 pixels are hidden.
