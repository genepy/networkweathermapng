# Study: ideas from PHP Network Weathermap 2.0

Date: 2026-10-29. Source studied:
[Cacti/plugin_weathermap](https://github.com/Cacti/plugin_weathermap), tag
`v2.0` compared with `v1.7`, and its `develop` branch at commit `24ad23b`.

The goal is to find ideas the 2.0 release has that NetworkWeathermapNG does
not, and say which are worth taking. This is a written study: no code was
copied.

## License: reimplement, never copy

- The repository holds an MIT `LICENSE` (copyright thurban, 2019) and a GPL-2
  `COPYING`. Every file added or rewritten for 2.0 that matters here carries
  a **GPL-2-or-later** header (The Cacti Group, Howard Jones): for example
  `lib/WeatherMap.notes.php`, `lib/WeatherMap.animation.php`,
  `lib/editor.history.php`, `lib/editor.colours.php`, `js/editor.js`.
- NetworkWeathermapNG is MIT. GPL-2 code cannot be copied into it without
  relicensing the whole project, so **nothing from 2.0 may be copied**,
  including JavaScript and CSS. Ideas and behaviour are free to reimplement;
  they are credited in [THIRD-PARTY-NOTICES.md](../../THIRD-PARTY-NOTICES.md)
  under "Ideas from other projects".
- The config keywords and `SET` hint names below are a file format. Reading
  them for import compatibility is not copying code.
- The 13 new 96 px PNG icons (`AP`, `NAS`, `Server`, `Printer`, `UPS`,
  `CCTV-*`, `branch-office`, `clinic-red`, `corporate-building`,
  `data-centre`, `hospital-red`, `warehouse`) state no author, origin or
  license. **Do not bundle them.** The icon libraries the maintainer listed
  (networking-icons, Network-Icons-SVG, eve-ng, ecceman/affinity,
  drawio-libs) are the way to go, each with its own license check.

## What 2.0 adds, and where we stand

| 2.0 feature | NetworkWeathermapNG today |
|---|---|
| Free-text map notes with alignment (`SET editor_note 1`) | Missing. Zones are labelled rectangles, not free text |
| Comments that follow curved links (`COMMENTCURVE 0/1`, default on) | Missing. Comments are straight; `COMMENTCURVE` is "Unrecognised config" on import |
| Traffic colour presets for the DEFAULT scale (7 palettes) | Missing. The SVG editor has no colour scale editor at all |
| Optional pulse/glow on links at 80 % load or more (`SET traffic_pulse`) | Missing |
| Separate icon and background libraries: upload, delete, searchable picker | Partly: a picker lists files, but upload only happens through a migration zip |
| Background fit or stretch to the canvas (`SET background_sizing fit/stretch/image`) | Missing |
| Node label position presets inside the icon: top, centre, bottom (`SET editor_label_position N60/C/S60`) | Partly: the label can be dragged (#23); no one-click presets |
| Readable defaults for a new blank map (1400 x 750, compact arrows, TrueType font) | To check: new maps start from our own template |
| Default link capacity 1000M when none is set | Ours comes from the data source (interface speed) |
| Two-step undo and redo, per browser tab, refused when the map changed elsewhere | We already have 100 steps per map. The "changed elsewhere" protection is missing |
| Atomic saves with locks | Already done (`MapService` writes through a temp file and `rename`) |
| Sharper, individually clickable hover graphs | Partly: #22 shows a link's traffic graph |
| Delete unused config files, map title at creation, gallery thumbnails | Already covered by map management |

## Shortlist, by priority

1. **Import 2.0 maps cleanly.** Read `COMMENTCURVE`, and the hints
   `editor_note`, `editor_note_align`, `editor_note_escaped`,
   `traffic_pulse`, `background_sizing` and `editor_label_position`. Notes
   become our notes (item 2), the others map to items 4 to 7. Escaped note
   text decodes `\\` and `\n`. Fits the existing "Map import fixes" item.
2. **Map notes.** A free-text block (several lines, left/centre/right,
   font, colour, optional box) placed anywhere, drawn sharp in the SVG and
   PNG renders, editable in the SVG editor. Useful for legends, contacts,
   maintenance windows.
3. **Colour scale editor with presets.** Edit the DEFAULT scale in the SVG
   editor and offer a few palettes. Pick our own palettes, readable in light
   and dark themes and safe for colour-blind people. This goes with the
   maintainer's "contrast and colours in dark/light mode" request.
4. **Highlight saturated links.** In the viewer, links at 80 % or more
   pulse or glow, switchable per map, and off under
   `prefers-reduced-motion`. Cheap in SVG (CSS animation on the link path).
5. **Comments along curved links.** Draw IN/OUT comments on a text path that
   follows the curve, falling back to straight text when the bend is too
   tight. Helps with the maintainer's "labels hidden by other links" request.
6. **Icon and background library.** Upload, rename, delete and search
   images from the editor, icons and backgrounds kept apart, SVG sanitised
   (we already have `SvgSanitizer`). This is the maintainer's "network icons
   with easy add and remove in the editor" request: do it there.
7. **Background fit or stretch.** One map setting: natural size, fit
   (keep the ratio) or stretch to the canvas.
8. **Label position presets.** Top, centre or bottom of the icon in one
   click, next to the dragging already possible. Goes with the "move a node
   label with the mouse" request.
9. **Undo refused when the map changed elsewhere.** If another user or tab
   saved the map since the step was recorded, refuse the undo instead of
   overwriting their work. Small change in `ConfigHistory`.
10. **Readable defaults for a new map.** Check our blank map template
    against the 2.0 choices (landscape size, compact arrows, TrueType font).

Not worth taking: the Cacti-specific work (graph tree navigation, Boost,
CSP nonces for Cacti, poller cron fixes), the default 1000M capacity, and
the 2-step undo limit.

## Credits

Ideas above come from PHP Network Weathermap 2.0 by The Cacti Group, Inc.
and its contributors, after Howard Jones' PHP Network Weathermap
(<https://github.com/Cacti/plugin_weathermap>, GPL-2.0-or-later for the 2.0
files). Each feature built from this list keeps a line in
THIRD-PARTY-NOTICES.md.
