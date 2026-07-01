Disclaimer: this project is a work in progress and has yet to be deployed

# Tally.

DeGiro is alright, unless you want insight into your portfolio.
This project fixes this.

Tally accepts DeGiro's CSV exports and gives the insights I kept missing.

**Live:** https://tally.d-jordy.nl

---

## What it does
- **Portfolio chart** — a timeline of your portfolio value
- **Dividends** — history, totals, and per-asset insight
- **Performance vs deposits** — see what you put in vs what the market did
- **Projections** — a rough estimate of where things might head
- **Allocation & summaries** — quick breakdowns so the numbers make sense

---

## Tech stack
- **Laravel** (PostgreSQL)
- **Filament 5** panel — the whole UI, with a custom "Tally" theme
- **Livewire + Tailwind v4**
- **ApexCharts** (via `leandrocfe/filament-apex-charts`)

---

## Disclaimer
Not financial advice. Just software.

Projections and dates may be wrong, always double check.
