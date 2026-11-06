# UI redesign: plan and decisions

Summary of the UI/UX review written on 2026-11-03 and reviewed by the
maintainer on 2026-11-04. Each step below is one task, one branch and one
pull request; their lines are in [docs/ROADMAP.md](../ROADMAP.md).

## Goals

The application has the features; they are scattered. The redesign brings
them together without changing what the application can do:

1. One application shell for every page, the editor included.
2. Two spaces instead of three: *Maps* (view, search, edit, organise) and
   *Settings* (administration). The *Editor* tab, a second list of maps,
   goes into *Maps*.
3. The map is the centre: a full-page viewer with floating controls, and a
   *Map settings* panel that holds every setting of a map.
4. One design system: tokens, one icon set, one style per component.
5. Accessible by default: WCAG 2.2 AA contrast, visible focus, keyboard.

## Decisions (2026-11-04)

1. *Maps* and *Editor* merge into one *Maps* page.
2. A map's name and title merge into one.
3. Editor: a contextual bar by default, movable toolbars as an option.
4. The classic editor is removed (step 11).
5. Start with steps 1 and 2.

## Design system

`src/public/assets/css/tokens.css` holds every colour, spacing, radius,
shadow, type and motion value, for the light and dark themes. Every page
loads it first; the other style sheets only use its values.
`tests/App/DesignTokensTest.php` checks the contrast of the colour pairs
(text 4.5:1, field and control edges and focus ring 3:1).

| Role | Light | Dark |
|---|---|---|
| Background | `#f8fafc` | `#0b1120` |
| Surface | `#ffffff` | `#111827` |
| Text | `#0f172a` | `#e2e8f0` |
| Secondary text | `#475569` | `#94a3b8` |
| Field edge (3:1) | `#848e9f` | `#64748b` |
| Decorative separator | `#e2e8f0` | `#1f2a3c` |
| Accent (white / dark text) | `#2563eb` | `#60a5fa` |
| Success | `#15803d` | `#4ade80` |
| Danger | `#dc2626` | `#f87171` |
| Warning (text) | `#a16207` | `#facc15` |

- Spacing on a 4 px grid; radii 6 px (controls), 10 px (floating bars),
  14 px (cards, panels); two shadow levels; motion 150 to 200 ms, off with
  `prefers-reduced-motion`.
- Buttons: *primary* (accent fill, once per view), *secondary* (outline),
  *ghost*, *danger*. Main action on the right, destructive on the left.
- Icons: one stroke icon set, a subset of Lucide (ISC) in
  `src/public/assets/icons/ui.svg`, used with `icon('name')` in PHP. No
  emojis in the interface. The editor keeps its own toolbar sprite
  (`toolbar-icons.php`), drawn in the same style.
- Writing: sentence case, one verb per action used everywhere (*New map*,
  *Edit*, *Settings*, *Delete…* where the ellipsis announces a
  confirmation), English first with French in the same change.

## Libraries

No framework and no build step: the Content-Security-Policy only allows
same-site scripts and the application must work on closed networks. Native
first (`<dialog>`, `popover`, View Transitions, `:has()`, `light-dark()`).
Libraries worth adding, copied into `src/public/assets/vendor/` with their
license recorded: uPlot (MIT, graphs), Tom Select (Apache-2.0, long lists
with search), SortableJS (MIT, reordering), Floating UI (MIT, menu
positions in the zoomed editor).

## Steps

1. Tokens, contrast and icons: `tokens.css`, new colours, field edges at
   3:1, icon sprite, emojis removed. No layout change.
2. App shell: thin top bar, user menu (language, theme, password, logout),
   no footer, bottom navigation on phones, toasts, confirmation dialog,
   error pages.
3. One Maps page: thumbnails and list on the same page, filter chips,
   actions on hover, bulk actions; the *Editor* tab redirects to *Maps*.
4. Viewer: floating bar, Live / Last 24 h, one fullscreen mode, status and
   legend at the bottom, kiosk controls that fade out.
5. Map settings panel: every setting of a map together; name and title
   merged, asking which one to keep where they differ.
6. Settings: side navigation, pages as panels, data sources in a drawer
   with a test on the row.
7. Command palette `Ctrl+K` (home, viewer, settings).
8. Editor, part 1: top bar, *Add* panel, map properties in the inspector,
   shortcuts behind `?`.
9. Editor, part 2: floating contextual bar, palette in the editor, movable
   toolbars as an option.
10. Hover card with uPlot graphs (joins item 4 of the Nine IX study).
11. Remove the classic editor: list what only it can do
    (`editor/visual.php`, `editor/edit.php`, `EditorController`,
    `/editor/classic/{id}`), port it to the SVG editor or report it, then
    remove the views, routes, scripts, unused strings and the link from the
    SVG editor. Done: the link's info URL, the hover graph of nodes and links, the
    out bandwidth and *Tidy* were the only things missing from the SVG
    editor; they were ported, nothing was left to report.
