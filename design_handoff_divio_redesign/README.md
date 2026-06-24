# Handoff: Divio — Portfolio & Dividend Tracker Redesign (Hybrid "Ledger/SaaS" direction)

## Overview
Visual redesign of a self-hosted personal portfolio & dividend tracker (DEGIRO broker, EUR reporting).
Private dashboard for the owner + a few friends, ~10 holdings each. Tone: calm, data-dense but legible,
trustworthy. The **functionality stays the same** — this is a visual/layout redesign only.

The chosen direction is a **hybrid**: the structure and grid of a clean light SaaS dashboard (low-risk in
Filament) combined with the *character* of an editorial/ledger look — paper-toned background, serif display
numbers, monospace tabular figures, hairline borders instead of heavy shadows, and an underlined text-link nav.

## About the Design Files
The files in this bundle are **design references created in HTML** — prototypes showing the intended look and
behavior. They are **not production code to copy directly**. The task is to **recreate these designs in the
target codebase** using its established stack and patterns:

- **Vue 3 (SFC) + Inertia.js**, page-based (no client-side router).
- **TailwindCSS** (utility-first) + **@tailwindcss/forms**. No other CSS/component frameworks.
- **ApexCharts** via **vue3-apexcharts** for ALL charts.
- The app is rendered through **Filament** (Laravel) panels with a **custom theme**. Most of this look is
  achievable via a Filament custom theme (`php artisan make:filament-theme`) — tokens, fonts, colors,
  Tailwind classes on table columns, and light Blade view overrides. See "Filament implementation notes" below.
- Icons: inline SVG / Heroicons, kept light.
- Responsive: desktop primary, usable on mobile. Tables stay small (~10 rows) — no datagrid/virtual scroll.

### Files in this bundle
- `Divio Tracker — Hybrid.dc.html` — **the chosen, fully worked-out direction.** Working top-nav switches
  between Portfolio / Dividends / Projections; charts in the hybrid style; plus a component library
  (buttons, badges, form inputs w/ error, dropdown, delete modal, empty-state).
- `Exploration — All Directions.dc.html` — the earlier side-by-side exploration (A Clean SaaS, B Warm,
  C Ledger, D Soft consumer app, E Hybrid). Reference/context only; **implement E (the hybrid)**.

> Note: these are authored as "Design Components" (`.dc.html`). Open them in a browser to view. The markup
> uses inline styles; translate to Tailwind utilities + a small custom theme. Ignore the `<x-dc>` /
> `data-dc-script` wrapper — only the visual result and the documented tokens below matter.

## Fidelity
**High-fidelity.** Exact colors, typography, spacing and interactions are specified below. Recreate
pixel-faithfully using Tailwind + the Filament custom theme. Where a value isn't listed, read it from the HTML.

---

## Design Tokens

### Colors
| Token | Hex | Use |
|---|---|---|
| Paper bg (app) | `#efece4` | Outermost page background |
| Surface (frame) | `#f7f6f2` | App frame / panel background |
| Card / raised | `#fcfbf8` | Cards, nav bar, table containers, inputs hover-surface |
| Pure white input | `#ffffff` | Text inputs |
| Border (hairline) | `#e6e3da` | Card & frame borders |
| Row divider | `#ece9e0` | Table row top-borders |
| Ink (primary text / accent) | `#1a1a1a` | Headings, primary buttons, active nav, chart line, KPI top-rule |
| Body text | `#2a2a2a` | Table numeric cells |
| Muted label | `#9a9488` | Mono labels, secondary meta |
| Muted nav / text | `#8a8474` | Inactive nav, soft text |
| Faint / placeholder | `#c4bfb3` / `#cdc8ba` | Em-dash, neutral KPI top-rule, segmented inactive |
| **Positive (P&L)** | `#2f7d52` | Gains, "received", growth rate |
| Positive bg (badge) | `#e6efe6` | "CONFIRMED" badge bg |
| **Negative (P&L)** | `#c0392b` | Losses, fees, danger, "Divio." dot, error |
| Negative bg (badge) | `#fbeceb` / `#fbecea` | Danger button bg; error input bg if needed |
| Danger border | `#ecc4bf` | Danger button border |
| Estimate bg | `#efe9dc` | "ESTIMATE" badge bg / empty-state icon bg |
| Estimate text | `#a89c86` | Projected/estimate italic text |
| Dashed border (projected) | `#d8d2c4` | Projected table & input borders, empty-state dashed |

### Typography
- **Display / headings & all monetary figures:** `Spectral` (serif). Weights 600. Page titles 26px;
  card titles 16px; KPI big numbers 24px; secondary KPI 19–22px.
- **Body / UI / labels:** `Inter`. 12–13px, weights 400/500/600.
- **Numbers in tables, labels, meta, axis ticks:** `IBM Plex Mono`, 400/500/600. Apply
  `font-variant-numeric: tabular-nums` to all aligned numbers.
- KPI mono labels: 10px, `letter-spacing:.07em`, uppercase, color `#9a9488`.
- Load via Google Fonts: Inter (400/500/600/700), Spectral (400/500/600/700), IBM Plex Mono (400/500).

### Spacing / radius / shadow
- Card padding: 14–18px. Page content padding: 24px. Grid gaps: 12–14px.
- Radius: frame 12px, cards 8px, buttons/inputs 7px, badges 4px, modal 12px.
- Shadows are minimal: frame `0 1px 3px rgba(40,36,28,.06)`; dropdown `0 4px 16px rgba(40,36,28,.10)`;
  modal `0 12px 40px rgba(40,36,28,.18)`. Prefer **hairline borders over shadows**.

### Signature details
- **KPI cards** carry a 2px colored **top-border** as an accent: ink `#1a1a1a` for the headline metric,
  `#2f7d52` for positive metrics, `#cdc8ba` (neutral) for the rest.
- **Table headers**: mono, 10px, uppercase, `#9a9488`; section title row has a `border-bottom:2px solid #1a1a1a`.
- **Numbers right-aligned**; instrument name left-aligned in Inter 600. Gains `#2f7d52`, losses `#c0392b`,
  none = `—` in `#c4bfb3`.
- **Brand mark**: `Divio` in Spectral 600 with a `.` in `#c0392b`.

---

## Screens / Views

### Shared shell (authenticated layout)
- **Top bar**, 58px, bg `#fcfbf8`, bottom border `#e6e3da`. Left: brand `Divio.` + nav links
  (Portfolio · Dividends · Projections · Accounts). Right: user email in mono `#5a564d` + circular
  avatar (28px, bg `#1a1a1a`, white initial).
- **Nav links**: text only, 13px Inter 500. **Active** = `#1a1a1a` with `border-bottom:2px solid #1a1a1a`;
  inactive = `#8a8474`, no underline. (Not pills — this is a deliberate departure from default admin chrome.)
- Guest layout (auth pages) reuses the paper bg + serif headings, centered card, no nav.

### 1. Portfolio (homepage/dashboard)
- **Title row**: "Portfolio" (Spectral 26px) + right meta `EUR · 24 JUN 2026` (mono 12px `#9a9488`).
- **KPI row 1** (4-col grid): Market value (ink top-rule), Deposited (neutral rule), Net gain
  (green rule, shows `+27,1%` sub), Unrealised P&L (green rule).
- **KPI row 2** (3-col grid, no top-rule): Realised P&L, Dividends, Fees (net of promo) — fees in `#c0392b`.
- **Line chart** card: "Portfolio value over time" + range toggle (1M/6M/1Y/ALL, mono, active underlined).
  ApexCharts area, ink line `#1a1a1a` width 2.5, subtle gradient fill (opacity .12→0), grid `#ece9e0`,
  axis labels mono `#9a9488`, y-format `€{k}k`, tooltip `€{nl-NL}`.
- **Positions table**: columns Instrument · Qty · Avg cost · Latest · Value · Unrealised · Dividends.
  Mono numeric cells, hairline row dividers, header with `border-bottom:2px solid #1a1a1a`.
- **Empty state** (no positions): dashed card, `+` icon tile, "Nog geen posities", subtext
  "Importeer je DEGIRO-transacties om te beginnen.", primary "CSV importeren" button. (See component library.)

### 2. Incoming Dividends
- KPI row (3-col): Expected next 12 months (ink rule), Received last 12 months (green rule),
  Dividend-paying holdings (neutral rule).
- **Bar chart** card: "Expected dividend income per month". Stacked ApexCharts bar, two series:
  **Confirmed** `#2f7d52` and **Projected** `#d8d2c4` (softer/lighter). Column width ~52%, radius 2,
  legend top-right Inter 11px.
- **Two tables side by side**:
  - **Upcoming** — solid card, "CONFIRMED" badge (`#e6efe6`/`#2f7d52`). Columns Instrument · Ex-date ·
    /share · Expected.
  - **Projected** — visually softer: bg `#faf8f2`, **dashed** border `#d8d2c4`, italic muted text `#a89c86`,
    "ESTIMATE" badge (`#efe9dc`/`#a89c86`). Same columns. This soft/dashed/italic treatment is the
    required confirmed-vs-estimate distinction.

### 3. Projections
- Title row + controls: **horizon segmented toggle** (1Y/3Y/5Y/10Y) — bordered group, active segment
  bg `#1a1a1a` text `#fcfbf8`, others `#8a8474`; **annual contribution** input (`€ 6.000`, mono).
- KPI row (4-col): Current portfolio (neutral rule), **Projected in N years** (inverted: bg `#1a1a1a`,
  white serif number), Blended growth rate (green rule), Dividends in N years (neutral rule).
- **Growth chart** card: ApexCharts area over horizon (categories `nu,1..10`), ink line, subtle fill,
  x-axis title "jaren".

### 4–7 (derive from the system; not separately mocked)
- **Accounts**: table (Name · Broker · Last import · actions) using the same table style; "new account"
  page = form card; **delete modal** = the modal in the component library.
- **Import**: CSV upload card (DEGIRO transactions + account ledger) per account — use the empty-state /
  dashed-card pattern for the dropzone, primary button to submit.
- **Profile**: 3 stacked form-card sections (profile data, change password, delete account) — danger button
  for delete, form inputs as documented.
- **Auth** (Login/Register/Forgot/Reset/Verify/Confirm + public Welcome): guest layout, centered card on
  paper bg, Spectral heading, Inter labels, documented inputs + primary button. Welcome = simple marketing-lite
  hero in the same palette.

---

## Interactions & Behavior
- **Top-nav** switches pages (in the real app via Inertia page visits / Filament navigation — not a JS router).
  Active state styled as above.
- **Range toggle** (Portfolio) and **horizon toggle** (Projections) re-query/re-render the chart series.
  Annual-contribution input recomputes the projection curve and the 4 projection KPIs.
- **Charts**: render with vue3-apexcharts. Re-render when their series change. (In the HTML prototype,
  charts are (re)drawn on page switch because hidden containers have zero width — in Vue, mount the chart
  only when its page/tab is visible, or call `.updateSeries()` on data change.)
- **Modal**: delete confirmation, focus-trapped; primary danger action `#c0392b`, secondary outline.
- **Dropdown** (user menu): email header row, items, danger "Log out" separated by a top border.
- **Form validation**: invalid input gets `#c0392b` border + helper text below in `#c0392b` 12px Inter.
- **Hover/focus**: buttons darken slightly; inputs keep border, add a subtle focus ring in ink/positive as
  fits the theme (use `@tailwindcss/forms` defaults retuned to these colors). Disabled button = bg `#f0eee7`,
  text `#bdb4a2`, `cursor:not-allowed`.

## State Management
- `activePage` (portfolio/dividends/projections) — server-driven via Inertia in production.
- Projections: `horizon` (1/3/5/10) and `annualContribution` (number, EUR) → derived `projectedValue`,
  `blendedGrowthRate`, `projectedDividends`, and the growth series.
- Portfolio: `range` (1M/6M/1Y/ALL) → value series window.
- Data fetching: holdings, transactions, dividends (confirmed + projected), account list — from the
  existing backend; this redesign doesn't change the data model.

## Components (exact styling — see component library section in the HTML)
- **Buttons**: primary (ink bg, `#fcfbf8` text, radius 7, 9×17px, Inter 600 13); secondary (transparent,
  1px ink border); danger (bg `#fbeceb`, text `#c0392b`, border `#ecc4bf`); disabled (bg `#f0eee7`,
  text `#bdb4a2`).
- **Badges**: CONFIRMED (`#e6efe6`/`#2f7d52`), ESTIMATE (`#efe9dc`/`#a89c86`) — mono 9px, `.05em`, radius 4.
- **Form input**: white bg, 1px `#d8d2c4` border, radius 7, 9×12px, Inter 13; label Inter 12 `#4a463d` above;
  error variant = `#c0392b` border + helper text.
- **Dropdown**: white card, 1px `#e6e3da`, radius 8, soft shadow, header row on `#f7f6f2`, danger item.
- **Modal**: `#fcfbf8` card, radius 12, big shadow; Spectral title; Inter body `#6a6456`; right-aligned
  cancel(outline)+confirm(danger).
- **Empty state**: dashed `#d8d2c4` card on `#faf8f2`, icon tile (`#efe9dc` bg, Spectral `+` in `#a89c86`),
  Spectral title, Inter subtext `#8a8474`, primary button.

---

## Filament implementation notes (for the developer)
- **Easy via custom theme** (`theme.css` + tailwind config): fonts (`@font-face`/Google), the color palette,
  paper background, radii, mono tabular numbers, table column classes (right-align, color formatting via
  `->color()` / `->formatStateUsing()` / `extraAttributes`), badge styles, button colors.
- **Moderate (publish/override Blade or build custom widgets)**: the **hairline KPI cards with colored
  top-rule** are not the default stat widget — build a small custom Blade stat widget. Removing the default
  `rounded-xl`/`ring`/shadow chrome on panels/sections is a theme/override task.
- **Departs from defaults**: nav as underlined text links instead of Filament's sidebar/topbar pills — a
  theme override; keep if you want the editorial feel, otherwise the rest of the system stands on its own.
- Charts: vue3-apexcharts with the exact options documented (ink line, `#ece9e0` grid, mono axis labels,
  green/soft-grey stacked bars for confirmed/projected).

## Assets
No raster assets or logos required. The "Divio." wordmark is type (Spectral 600 + `#c0392b` period).
Icons should come from your existing Heroicons set. All chart visuals are generated by ApexCharts.
