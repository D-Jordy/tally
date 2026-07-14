# Tally — project guide

Self-hosted portfolio & dividend tracker (DEGIRO-focused, EUR reporting, ~10 users). Laravel 13 + Filament 5 panel (whole UI), PostgreSQL, database queue. Brand = `config('app.name')` ("Tally") + red dot — never hardcode the name. `divio-*` CSS vars / `x-divio.*` components are the design codename, leave them.

## Local dev (Dockerized — WSL2)
- Dir: `~/Code/tally`. Run everything in containers, never on host:
  - `docker compose exec app php artisan ...` / `composer ...`
  - Format: `docker compose exec app vendor/bin/pint` (default Laravel preset — un-aligns `=>` arrays; repo had hand-aligned, don't fight it)
- Container runs as **root** → artisan-generated files are root-owned; `docker compose exec app chown -R 1000:1000 <path>` before host-editing.
- Dev URL via Traefik: `http://tally.localhost` (no published 8000 port). `docker-compose.override.yml` = dev only (Traefik localhost, router `tally`). The shared external `stack` network hosts many Laravel projects that all expose a generic `app` service, so nginx targets `tally-app:9000` (project-unique alias on the `app` service), not `app:9000` — otherwise `fastcgi_pass` round-robins PHP to foreign FPMs (cabcon etc.).
- **Tests MUST run on Postgres**, not sqlite (compute actions use `DISTINCT ON`). `phpunit.xml` → dedicated `portfolio_testing` DB. Create once: `docker compose exec db psql -U portfolio -c 'CREATE DATABASE portfolio_testing'`. Don't let a cached config point tests at the dev `portfolio` DB — `migrate:fresh` wipes demo data. Re-seed: `db:seed --class=DemoSeeder`.
- Filament v5 test gotcha: `->fillForm()` is a silent no-op here → use `->set('data.field', ...)`. ApexChart widgets need `->plugins([FilamentApexChartsPlugin::make()])` on the panel or they 500 in real HTTP (passes in Livewire::test) — smoke-test the real route.

## Architecture
- Domain logic in **Jobs/Actions** (`ComputePortfolio`, `ComputePortfolioHistory`, `ComputeProjections`, `ComputeIncomingDividends`, `ImportBrokerCsv`); Artisan/Filament are thin wrappers. `SyncMarketDataJob` + `YahooFinanceAdapter` for market data.
- Money = DECIMAL never float. GBp/GBX (pence) ÷100 → GBP. FX stored inverted ("1 foreign = X EUR", always multiply). All normalisation in the adapter, not scattered.
- Auth-scoping via `App\Models\Concerns\BelongsToUser` (auth-guarded creating-hook + global scope; jobs/CLI see all rows). Open registration, fully isolated per-user data.
- Schedule (`routes/console.php`): `SyncMarketDataJob` daily 02:00 → DB queue → `worker` container runs it.

## Production server (Hetzner VPS)
- SSH: `root@46.62.196.140` (no alias). Live: **https://tally.d-jordy.nl**. Compose **v2 plugin** installed (`docker compose`, not v1 `docker-compose`).
- Isolated per-service stacks under `/srv/`, all on one external network `web`; only Caddy publishes 80/443:
  - `/srv/caddy/` — Caddy + TLS (Let's Encrypt prod, global email). Main `Caddyfile` has vault + d-jordy.nl blocks + `import conf.d/*.caddy`; bind-mounts `/srv/tally/Caddyfile` for the tally block. Reaches apps by service-name alias on `web` (`tally-web`, `vaultwarden`, `portfolio`).
  - `/srv/tally/` — git clone of this repo. Run: `docker compose -f docker-compose.yml -f docker-compose.prod.yml ...` (prod overlay = no host ports, joins `web`, alias `tally-web`). Project name `tally`. **`.env` is on-server only, NOT committed** (real APP_KEY + DB pw).
  - `/srv/vaultwarden/`, `/srv/portfolio/` (static nginx) — unrelated to Tally, leave alone.
- DB lives in container `tally-db-1` (postgres:16), volume `tally_pgdata`. Code is bind-mounted from `/srv/tally`; vendor/node_modules/build come from the built image via volumes.

### Operating prod
- Logs: `ssh root@46.62.196.140 'docker logs tally-app-1 --tail 50'` (also `tally-worker-1`, `tally-scheduler-1`, `caddy-caddy-1`). App log: `docker exec tally-app-1 tail -n 100 storage/logs/laravel.log`.
- Artisan/tinker: `docker exec tally-app-1 php artisan ...`.
- **Deploy/update:** `cd /srv/tally && git pull && docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`. App auto-migrates on boot (entrypoint `migrate --force`).

### Known warts
- **Boot migration race**: app+worker+scheduler all `migrate --force` at boot → worker crash-loops ~10s then self-heals; recurs each redeploy. Cosmetic. Fix = gate migrate to app only.
- **Mail = log driver** → Filament password-reset / email-verification don't send. Verify link is in `storage/logs/laravel.log`. Set SMTP in `/srv/tally/.env` to fix properly.
- Trusts all proxies (`bootstrap/app.php`) — fine, Caddy is sole ingress.

## Git
- Branch per change `feature/<n>-<slug>` from `main`. Commits/PRs in English, compact. **No Claude co-author trailer.** Write/run a test for every change.
