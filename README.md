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
- **Laravel**
- **Inertia.js + Vue**
- **Tailwind**
- **ApexCharts**
---

## Running locally
I have yet to set up Docker, but you can spin up by cloning the repo and

```bash
# Install PHP and Node dependencies
composer install
npm install

# Copy the environment file and generate an app key
cp .env.example .env
php artisan key:generate

# Run migrations (ensure your database is set up in .env first)
php artisan migrate

# Build frontend assets and start the local server
npm run dev
php artisan serve
```
---

## Data & privacy
If ran locally or self-hosted, your data never leaves your machine.

If you are using the live version at `tracker.d-jordy.nl`, your data will be stored in my database. 

---

## Disclaimer
Not financial advice. Just software.

Projections and dates may be wrong, always double check.
