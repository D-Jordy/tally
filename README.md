Disclaimer: this project is a work in progress and has yet to be deployed

# Portfolio Tracker

DeGiro is alright, unless you want insight into your portfolio.
This project fixes this.

This tracker accepts DeGiro's CSV exports and gives the insights I kept missing.

**Live:** https://tracker.d-jordy.nl

---

## What it does
- **Portfolio chart** — a timeline of your portfolio value  
- **Dividends** — history, totals, and per-asset insight  
- **Performance vs deposits** — see what you put in vs what the market did  
- **Projections** an attempt at giving atleast a rough estimate of the future
- **Allocation & summaries** — quick breakdowns so the numbers make sense  
---

## Tech stack
- **Laravel** (PostgreSQL)
- **Filament 5** panel — the whole UI, with a custom "Divio" theme
- **Livewire + Tailwind v4**
- **ApexCharts** (via `leandrocfe/filament-apex-charts`)
---

## Running locally
The app runs on Docker Compose (PHP-FPM, nginx, PostgreSQL, queue worker, scheduler).

```bash
# Copy the environment file
cp .env.example .env

# Build and start the stack
docker compose up -d --build

# Install dependencies, set up the app and build the Filament theme
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app npm install
docker compose exec app npm run build
```

The panel is then served at `http://localhost:8000` (open registration).

---

## Data & privacy
If ran locally or self-hosted, your data never leaves your machine.

If you are using the live version at `tracker.d-jordy.nl`, your data will be stored in my database. 

---

## Disclaimer
Not financial advice. Just software.

Projections and dates may be wrong, always double check.
