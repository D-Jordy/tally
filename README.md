# Tally

Portfolio insight for DeGiro investors.

DeGiro is a solid broker, but its reporting is thin. Tally imports your DeGiro
CSV exports and turns them into the insights the platform leaves out: value
over time, dividend history, performance against what you deposited, and more.

**Live:** https://tally.d-jordy.nl

## Features

- **Portfolio chart**: portfolio value over time.
- **Dividends**: full history, running totals, and per-asset breakdowns.
- **Performance vs. deposits**: separate your contributions from market returns.
- **Projections**: rough forward estimates of portfolio growth.
- **Allocation & summaries**: at-a-glance breakdowns of how you're invested.

## Tech stack

- **Laravel 13** on PHP 8.3, backed by **PostgreSQL 16**
- **Filament 5** for the entire UI, with a custom *Tally* theme
- **Livewire** and **Tailwind CSS v4**
- **ApexCharts** via `leandrocfe/filament-apex-charts`

## Getting started

Requires Docker and Docker Compose.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

The app is served at `http://localhost:8000` (override with `APP_PORT`).
The Compose stack also runs a queue worker and scheduler.

## Disclaimer

Not financial advice, just software. Projections and imported figures may be
inaccurate; always verify against your broker statements.
