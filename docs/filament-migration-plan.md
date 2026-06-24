# Filament Migration + Divio Custom Theme — Master Plan

Self-contained plan. Covers (a) full frontend migration Inertia/Vue → Filament, and
(b) implementing the "Divio" custom theme from `design_handoff_divio_redesign/`.
A fresh session can start from this file.

## Decision & context
- **Decided 2026-06-24:** drop Inertia + Vue 3 + Vite + Breeze, move the whole frontend to a
  **Filament 5 panel**. Reasons: owner's Filament expertise (main project prodb-next is Filament 5),
  private tool (~10 holdings, no marketing site), and escaping the JS/Node/Vite toolchain
  (hit a Vite 8 vs Node 20.11 build wall).
- The Claude-design styling handoff is implemented as a **custom Filament theme**, not bespoke Vue.
- App is Laravel **13.8**, PHP **8.3**.

## Verified compatibility (composer dry-run, GO)
- `filament/filament` **v5.6.7** + `livewire/livewire` **v4.3.1** install clean on L13.8 — 32 packages, no conflicts.
- `league/csv` + `openspout/openspout` come in transitively (useful for CSV import).
- Charts: add `leandrocfe/filament-apex-charts` (keeps the exact ApexCharts options from the handoff).

## Survives untouched (frontend-agnostic — DO NOT rewrite)
- Domain Actions: `app/Actions/ComputeProjections.php`, `app/Actions/ComputeIncomingDividends.php`.
- Importers, Jobs (`app/Jobs/SyncMarketDataJob.php`), `YahooFinanceAdapter`, `DividendSyncService`.
- All Models (`app/Models/*`), migrations, normalisation rules (GBp→/100, FX inverted multiply, DECIMAL money).
- Job/Action pattern: logic in Jobs/Actions, Artisan commands stay thin wrappers.

## Gets thrown away / rewritten (Phase 7, only after parity)
- `resources/js/**` (Inertia Pages/Components/Layouts), `vue3-apexcharts`.
- Inertia middleware + root Blade view, `inertiajs/inertia-laravel`.
- Breeze controllers/routes/`Auth/`, the Vite frontend config + frontend npm deps.
- The 10 Breeze scaffold tests (Vite-manifest failures) disappear with Breeze → suite green, no Node build.

## Hard rules
- **Brand name comes from `config('app.name')` — NEVER hardcode "Divio".** Set `APP_NAME="Divio"` in
  `.env` + `.env.example`. The red "." is a presentational span, not part of the name.
- Per-user data isolation: every Resource query scoped to the auth user (open registration).
- Code style: typed closures/returns, no single-letter vars, collections over array_* , `$guarded=['id']`.
- Each phase must be working + tested before the next. Slop/delete (Phase 7) only after full parity.

---

## Design system (Divio "Ledger/SaaS" hybrid)
Full spec: `design_handoff_divio_redesign/README.md` + `Divio Tracker — Hybrid.dc.html` (open in browser).
Implement direction **E (hybrid)**. Key tokens to bake into the Filament theme:

### Colors
| Token | Hex | Use |
|---|---|---|
| Paper bg | `#efece4` | outermost page bg |
| Surface | `#f7f6f2` | app frame / panel bg |
| Card | `#fcfbf8` | cards, nav bar, tables, inputs |
| White input | `#ffffff` | text inputs |
| Border hairline | `#e6e3da` | card/frame borders |
| Row divider | `#ece9e0` | table row borders |
| Ink | `#1a1a1a` | headings, primary btn, active nav, chart line, KPI top-rule |
| Body text | `#2a2a2a` | numeric cells |
| Muted label | `#9a9488` | mono labels, meta |
| Muted nav | `#8a8474` | inactive nav |
| Faint | `#c4bfb3` / `#cdc8ba` | em-dash, neutral KPI rule |
| Positive | `#2f7d52` | gains, received, growth |
| Positive badge bg | `#e6efe6` | CONFIRMED badge |
| Negative | `#c0392b` | losses, fees, danger, brand dot, error |
| Danger btn bg/border | `#fbeceb` / `#ecc4bf` | danger button |
| Estimate bg/text | `#efe9dc` / `#a89c86` | ESTIMATE badge, projected italics |
| Dashed border | `#d8d2c4` | projected tables/inputs, empty-state |

### Typography
- **Spectral** (serif, 600): page titles 26px, card titles 16px, KPI big 24px, all monetary figures.
- **Inter** (400/500/600): body, UI, labels 12–13px.
- **IBM Plex Mono** (400/500/600): table numbers, labels, meta, axis ticks; `tabular-nums` on all aligned numbers.
- KPI mono labels: 10px, `letter-spacing:.07em`, uppercase, `#9a9488`.
- Google Fonts: Inter 400/500/600/700, Spectral 400/500/600/700, IBM Plex Mono 400/500.

### Spacing / radius / shadow
- Card padding 14–18px, page padding 24px, grid gaps 12–14px.
- Radius: frame 12, cards 8, buttons/inputs 7, badges 4, modal 12.
- Minimal shadows; **hairline borders over shadows**.

### Signature details
- KPI cards: 2px colored **top-border** (ink `#1a1a1a` headline, `#2f7d52` positive, `#cdc8ba` neutral).
- Table section header row: `border-bottom:2px solid #1a1a1a`; column headers mono 10px uppercase `#9a9488`.
- Numbers right-aligned; gains `#2f7d52`, losses `#c0392b`, none = `—` in `#c4bfb3`.
- Nav: underlined text links (active = ink + 2px underline; inactive `#8a8474`), NOT pills.

---

## Phased execution (maps to tracked tasks #1–#7)

### Phase 1 — Install + panel  (task #1)
```bash
git checkout -b feature/filament-migration
composer require filament/filament:"^5.6" leandrocfe/filament-apex-charts
php artisan filament:install --panels   # panel id "app", path "/"
```
Runs alongside the existing Inertia app. Smoke-test the panel boots. Nothing deleted yet.

### Phase 2 — Auth + user scoping  (task #2)
- `User implements FilamentUser` + `canAccessPanel(): bool { return true; }` (open registration).
- `AppPanelProvider`: `->login()->registration()->passwordReset()->emailVerification()->profile()`.
- Trait `BelongsToUser`: creating-hook sets `user_id` + global scope `where user_id = auth()->id()`.
  Apply to `Account` (downstream models scoped via account ownership).

### Phase 3 — Divio custom theme  (task #3)
```bash
php artisan make:filament-theme app
```
- `resources/css/filament/app/theme.css`: import Google Fonts, palette CSS vars, paper bg, radii, `tabular-nums`.
- Strip default `rounded-xl`/ring/shadow chrome → hairline `#e6e3da`.
- `resources/views/components/brand.blade.php`: `{{ config('app.name') }}<span style="color:#c0392b">.</span>`
  in Spectral 600. Wire via panel `->brandName()` / brand view. **No hardcoded "Divio".**
- Underlined text-link nav: topbar Blade override.
- `.env` + `.env.example`: `APP_NAME="Divio"`.

### Phase 4 — Accounts Resource + CSV import  (task #4)
- `make:filament-resource Account`: table (Name · Broker · Last import · DeleteAction modal) + create form.
- Import = `Action` with `FileUpload` (DEGIRO transactions + ledger CSV) → existing importers.
- Move `AccountController` / `ImportController` view logic into Actions; delete controllers.
- Tests: `Livewire::test(ListAccounts)->assertCanSeeTableRecords(...)`, import-action test.

### Phase 5 — Portfolio dashboard  (task #5)
- Custom page `app/Filament/Pages/Portfolio.php` (panel home), Blade view.
- KPI: custom Blade stat component with 2px colored top-rule. Row1 4-col (Market value/Deposited/Net gain/Unrealised),
  Row2 3-col (Realised/Dividends/Fees — fees `#c0392b`).
- ApexChart area widget (ink line 2.5, gradient .12→0, grid `#ece9e0`, mono axes, `€{k}k`, nl-NL tooltip)
  + range toggle 1M/6M/1Y/ALL (Livewire prop → `updateSeries`).
- Positions table (derived from transactions) + dashed empty-state ("Nog geen posities" / "CSV importeren").
- Extract `PortfolioController` calc into an Action. Tests.

### Phase 6 — Dividends + Projections pages  (task #6)
- **Dividends**: 3 KPIs; stacked bar (Confirmed `#2f7d52` / Projected `#d8d2c4`) from `ComputeIncomingDividends`;
  two tables — Upcoming (solid, CONFIRMED badge) + Projected (dashed/italic, ESTIMATE badge).
  Columns Instrument · Ex-date · /share · Expected.
- **Projections**: Livewire `$horizon` segmented (1/3/5/10) + `$annualContribution` input → recompute via
  `ComputeProjections`; 4 KPIs (Projected-in-N = inverted ink card, white serif number); growth area chart.
- Tests for both.

### Phase 7 — Rip out Inertia/Vue/Breeze + green suite  (task #7)
- Remove `resources/js/**`, Inertia middleware + root blade, Breeze (controllers/routes/`Auth/`),
  `vue3-apexcharts`, Vite frontend config, frontend npm deps, `inertiajs/inertia-laravel`.
- `routes/web.php` → Filament + (later) iCal feed only.
- Delete old Inertia page tests; full Feature suite green (no Vite manifest, no Node build).
- Update README.

---

## Charts (filament-apex-charts) — exact options
- Portfolio value: area, ink line `#1a1a1a` width 2.5, gradient fill opacity .12→0, grid `#ece9e0`,
  axis labels mono `#9a9488`, y `€{k}k`, tooltip `€{nl-NL}`.
- Dividends: stacked bar, two series Confirmed `#2f7d52` / Projected `#d8d2c4`, column ~52%, radius 2, legend top-right.
- Projections: area over horizon (categories `nu,1..10`), ink line, subtle fill, x-axis title "jaren".
- Re-render on `range` / `horizon` / contribution change.

## Open follow-ups (post-migration, not in scope here)
- iCal dividend feed (build-order Part 5, `spatie/icalendar-generator` in stack).
- Allocation overviews (sector/country), Box 3 / benchmark.
