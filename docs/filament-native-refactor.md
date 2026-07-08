# Refactor plan — lean on standard Filament components

Self-contained. A fresh session can start from this file + `CLAUDE.md`.

## Why
The whole UI is a Filament panel with a custom **divio** theme
(`resources/css/filament/app/theme.css`). That theme remaps Tailwind's gray scale
to warm paper tones and restyles `.fi-section`, tables, headings (serif), inputs and
the topbar — so **standard Filament components already inherit the divio look**.

Yet the pages (`Portfolio`, `Dividends`, `Insights`) hand-roll a lot of bespoke blade
that duplicates what stock components give for free: KPI cards, page controls, section
headings. This refactor deletes that duplication by using standard components, keeping
the look pixel-identical.

## Scope — do all three pages in ONE PR
Converting a single page leaves the others inconsistent. Branch
`feature/<issue>-filament-native`, convert Portfolio + Dividends + Insights together.

### Convert to standard components
1. **KPI cards** — replace the custom `x-divio.kpi` blade component
   (`resources/views/components/divio/kpi.blade.php`) and the hand-written KPI grids in
   each page blade with Filament `Stat`s (stats-overview widget or schema stat entries).
   - The 2px coloured top-rule → `->extraAttributes(['style' => 'border-top:2px solid …'])`
     per stat (ink `--divio-ink`, positive `--divio-positive`, neutral `#cdc8ba`).
   - The serif-number / mono-uppercase-label typography → one CSS rule in `theme.css`
     targeting the stat value/label classes, not per-blade inline styles.
   - Delete `x-divio.kpi` once no page references it.
2. **Page controls** — convert the hand-rolled buttons/inputs to Filament schema/form
   components where behaviour is preserved:
   - `Insights`: horizon segmented toggle + annual-contribution € input → `ToggleButtons`
     + `TextInput`. Keep the `annual_contribution_eur` persist-to-user-settings behaviour.
   - `Portfolio`: mode (value/pl/roi) + range (1M/6M/1Y/ALL) toggles. **Careful** — these
     drive the chart `:key` remount and persist to `localStorage` (`tally.pv.*`). Keep that
     wiring; only restyle the control surface, don't break the remount/persist.
3. **Section headings** — use `Section::make()` (serif heading comes from the theme)
   instead of inline `font-family:'Spectral'…` heading divs.

### Leave as-is (custom is correct here)
- **ApexChart widgets** — `PortfolioValueChart`, `ProjectionsGrowthChart`,
  `DividendsBarChart`, `SectorAllocationChart`. Already idiomatic widgets.
- **Position-size bars** (Insights allocation) — no native Filament component for a
  labelled progress-bar list; stays a blade partial.
- **Positions / dividends tables** — these render from **computed arrays**
  (`ComputePortfolio` / `ComputeIncomingDividends`), not Eloquent records. Filament Tables
  want a query; array-backed is fiddly. Only convert if it comes out genuinely cleaner —
  otherwise keep the current hand-built tables.

## Constraints (from CLAUDE.md)
- Look must stay identical — verify visually / smoke the **real routes** (ApexChart
  widgets can 500 in real HTTP while `Livewire::test` passes).
- Filament v5 test gotchas: `->fillForm()` is a silent no-op → use `->set('data.field', …)`;
  charts need the ApexCharts plugin (already on the panel).
- Brand from `config('app.name')`; per-user scoping intact; code style (typed
  closures/returns, no single-letter vars, collections, `$guarded=['id']`).
- **Don't let pint flatten the hand-aligned `=>` arrays** — only run pint on new/changed
  files, revert churn on hand-aligned ones (`git checkout` + re-apply the real edit).
- Tests MUST run on Postgres (`portfolio_testing` DB). Write/run a test per change; suite green.

## Definition of done
- `x-divio.kpi` deleted; all three pages use standard `Stat`s + themed controls.
- Pages render visually identical over real HTTP; full suite green; pint clean without
  flattening hand-aligned arrays.
