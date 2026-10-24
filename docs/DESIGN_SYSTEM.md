# Everafter Planner — Design System (dashboards, 2026)

Warm stone surfaces, rose-gold accents, one shared skin for all four roles. This
document is the contract for anyone adding a dashboard screen; it does not
describe page features (those live in `SYSTEM_OVERVIEW.md`).

---

## 1. Layers, and why the order matters

Every post-login page loads its stylesheets in this order:

| # | Sheet | Loaded from | Role |
|---|---|---|---|
| 1 | `admin/css/bootstrap.min.css` | page `<head>` | grid + components |
| 2 | `admin/css/dashboard.css` | page `<head>` | legacy template leftovers |
| 3 | `admin/css/modern-admin.css` | page `<head>` | shared components (cards, tables, buttons, forms, badges, overlays, vendor thread) |
| 4 | *the page's own `<style>` block* | page `<head>` | page-specific furniture |
| 5 | `admin/css/design-tokens.css` | `include/sidebar.php` | the palette (custom properties only) |
| 6 | `admin/css/app-shell.css` | `include/sidebar.php` | sidebar, top bar, popovers, content frame |
| 7 | `admin/css/design-system.css` | `include/sidebar.php` | re-asserts the few components pages redefine inline (`.stat-card`, `.filter-bar`, `.booking-table`, `.hint`, `.mono`) |

Sheets 5–7 land after the page's `<style>`, so at equal specificity the shared
skin wins without `!important` gymnastics. That is the whole trick of this
layer: **put a component in 5–7 only when several pages already define it**
and they must look identical. Anything a single page owns stays in that page.

`modern-admin.css` also `@import`s `design-tokens.css` at the top, so a page
that pulls the component sheet outside the sidebar shell still resolves its
variables.

---

## 2. Tokens (`admin/css/design-tokens.css`)

- **Brand** — `--brand-50/100/300/500/700/900`, rose-gold. `--brand-500`
  (`#D97757`) is the signature; `--brand-700` (`#A8553A`) is the *interactive*
  shade, because it passes AA as text and as a button fill.
- **Neutrals** — `--stone-50 … --stone-900`, warm stone. Text: `--text-strong`
  (headings), `--text-body` (copy), `--text-muted` (secondary, 5.8:1 on white).
- **Semantic** — `--success/--warning/--danger/--info` each with an `-ink`
  (text-safe), `-tint` (background) and `-line` (border) companion.
- **Surfaces** — `--surface-0/1/2`, `--surface-glass`.
- **Sidebar** — espresso stone (`--sidebar-bg`, `--sidebar-text`, …).
- **Shape & motion** — `--radius-card` 14, `--radius-control` 10,
  `--radius-pill`, `--shadow-soft`, `--shadow-lift`, `--focus-ring`,
  `--dur` 200ms, `--ease` ease-out. `prefers-reduced-motion` collapses `--dur`.
- **Legacy aliases** — `--admin-bg`, `--admin-accent`, `--app-emerald`, … are
  pointed at the new tokens so older rules pick up the palette instead of
  keeping slate-and-emerald.

### Contrast rules (WCAG 2.1 AA)

| Pair | Ratio | Allowed use |
|---|---|---|
| `--brand-700` on white | 5.2:1 | links, button fill (white label) |
| `--brand-500` on white | 3.2:1 | icons, rules, decoration, large text — **never** body copy |
| `--brand-900` on `--brand-50` | 8.4:1 | emphasis on tinted panels |
| `--text-strong` on white | 15.9:1 | headings |
| `--text-body` on `--surface-1` | 9.0:1 | body copy |
| `--text-muted` on white | 5.8:1 | secondary copy at 11.5px+ |
| `--stone-500` on white | 3.8:1 | icons, dividers only |

Semantic colours follow the same rule: `--success` for the bar or dot,
`--success-ink` for the words.

---

## 3. Building a new screen

1. Use the shell: `include_once 'include/sidebar.php'` — the top bar, badge
   counts, popovers and content frame all come free.
2. Reach for the components before writing CSS: `.card`, `.stat-card` (a KPI
   tile: `.stat-card.success` / `.info` / `.warning` / `.danger` for the left
   accent), `.filter-bar`, `.booking-table`, `.hint`, `.mono`, `.empty-state`,
   `.badge`, `.btn-outline-*`.
3. Style with tokens, never literals: `color:var(--text-muted)`, not
   `color:#64748B`. A hard-coded hex is exactly what this layer exists to stop.
4. Only add a `<style>` block for furniture unique to the page, and reference
   tokens inside it so a palette change still reaches it.
5. Stat-tile accents and semantic colours go through the tokens too — e.g.
   `style="border-left-color:var(--success)"` is preferred over `#059669`.

### KPI tiles

`.stat-card` (couple/planner/vendor lists) and `.card-counter` (admin
dashboard) share one language: white card, 14px radius, soft shadow, 3px
semantic accent on the left edge, muted uppercase label, tabular figures for
the value. `.card-counter` deliberately drops the old watermark glyph and caps
its number size (`clamp(19px, 1.9vw, 26px)`) so a long KSh figure never collides
with anything.

---

## 4. Cache headers (why a restyle is visible the day it ships)

`.htaccess` used to give CSS/JS a 7-day `max-age`, so a deployed stylesheet
change stayed invisible until browsers felt like asking again. It now sends
`Cache-Control: public, max-age=0, must-revalidate` for `.css` and `.js`:
browsers keep the file but revalidate each load, and Apache answers `304`
from the ETag. Images and fonts keep their 30-day caching.

---

## 5. Scope and known limits

- This layer styles the **post-login dashboards**. The public site keeps
  `css/modern.css` and its own palette.
- Pages still carry their own `<style>` blocks with older accent hexes
  (section headings, one-off panels). They are not wrong, just older — moving
  one onto tokens is a safe, local change.
- Icons remain Material Design Icons (`mdi`), which the whole product already
  uses from the CDN; swapping to Phosphor would mean touching every template
  for no functional gain.
