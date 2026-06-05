# Deploy plan: koszykomat.pl → DirectAdmin VPS (compute) + Supabase (PostgreSQL 17)

> Approved Plan Mode artifact (Module 1, Lesson 5). Audit trail of "what was supposed to happen" — consumed downstream by milestone planning as ground truth for what's deployed and which secrets are wired.

## Context

First production deployment of Koszykomat per `context/foundation/infrastructure.md`: compute on the own DirectAdmin VPS, **database on Supabase (managed PostgreSQL 17, Frankfurt, free tier)** — no database on the VPS. Already done by the user: domain **koszykomat.pl** bought, DNS on **Cloudflare (proxied / orange cloud)**, domain added to DirectAdmin (server IP **46.29.21.135**); the user creates the Supabase project and fills production `.env` credentials. Code lives in a **public GitHub repo**. Local repo is already switched to Postgres (ddev `type: postgres`, `.env.example` `DB_CONNECTION=pgsql`, CLAUDE.md updated).

Decisions confirmed: deploy = **GitHub Actions → rsync → symlink-swap releases** (no Deployer); server-side setup = **script + checklist for the user to run** (the agent does not SSH to the server).

## Repo state (explored at planning time)

- No `.github/workflows/`, no deploy scripts, no `context/deployment/`.
- `composer.json`: platform pinned `php 8.3.0`; scripts `test`/`lint`/`fix` exist. Vite 8 + Tailwind 4 build via `npm run build`.
- Health route wired: `bootstrap/app.php:12` → `health: '/up'`.
- `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database` — these now live in Supabase Postgres; no Redis needed.
- Only 3 default migrations; `routes/console.php` has nothing scheduled yet (scheduler entries are feature work — only the cron pipe gets wired now).
- Tests run on in-memory SQLite → CI needs no Postgres service container.

## What gets created in the repo

### 1. Dual Supabase connections in `config/database.php` + `.env.example`

Per the risk register ("transaction-pooler semantics break session-dependent queries"):

- `pgsql` (default, web requests) → Supavisor **transaction pooler, port 6543** (`DB_PORT=6543`).
- `pgsql_session` (migrations, long CLI jobs, nightly ingestion) → **session pooler, port 5432** (`DB_SESSION_PORT`, same host/user/pass envs).
- Locally both point at the same ddev Postgres (both ports = 5432) — zero ddev impact.
- `.env.example` documents both ports + a comment that production hosts must be the **pooler hostnames** (`*.pooler.supabase.com`) — the direct `db.<ref>.supabase.co` host is IPv6-only on the free tier and unreachable from a typical IPv4 VPS.

### 2. `.github/workflows/deploy.yml`

Single workflow, `on: push: branches: [main]` + `workflow_dispatch`. Two phases in one job (public repo → free Actions minutes):

**Build (CI):**
- Checkout, `shivammathur/setup-php` with PHP 8.3, Node 22.
- `composer install --no-dev --optimize-autoloader` (platform pin guarantees server compatibility), `npm ci && npm run build`.
- Quality gate before anything touches the server: `composer lint` + `composer test` (SQLite in-memory, no DB service needed).
- Stamp the release: write git SHA to a `REVISION` file in the artifact.
- Prune the payload (remove `node_modules`, `tests`, `.git`, dot-CI files) — rsync the rest.

**Deploy (over SSH to 46.29.21.135 — the IP, NOT the domain: Cloudflare proxy does not pass SSH):**
- Load key from secrets, `rsync -az --delete` into `~/domains/koszykomat.pl/releases/<run_timestamp>-<sha>/`.
- Execute `deploy/release.sh <release-dir>` on the server (script lives in the repo, not inline YAML).
- Verify from CI: `curl -fsS https://koszykomat.pl/up` returns 200 **and** `curl https://koszykomat.pl/_version` equals the pushed SHA — fail the run on mismatch (catches the opcache-stale-code risk from the risk register).

Required GitHub Actions repo secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST=46.29.21.135`, `DEPLOY_USER`, `DEPLOY_PATH=/home/<da-user>/domains/koszykomat.pl`.

### 3. `deploy/release.sh` (runs on the server, invoked by Actions)

Ordered exactly per the risk register (migrate **before** symlink swap — failed migration never goes live):

1. Link shared state into the new release: `shared/.env` → `release/.env`, `shared/storage` → `release/storage` (remove the rsynced `storage/`, symlink instead).
2. `php artisan migrate --force --database=pgsql_session` (session pooler — never the transaction pooler) — non-zero exit aborts the script, `current` untouched, CI goes red.
3. `php artisan config:cache && route:cache && view:cache && storage:link` (run inside the new release dir).
4. Atomic swap: temp-symlink + `mv -T` onto `current`.
5. Opcache flush — see strategy in setup checklist below.
6. Prune old releases, keep last 5.

DirectAdmin PHP binary path (e.g. `/usr/local/php83/bin/php`) parameterized at the top of the script.

### 4. `deploy/setup-server.sh` + `deploy/SERVER-SETUP.md` (one-time, run by user)

Script (run as the DA user over SSH) creates the layout:

```
~/domains/koszykomat.pl/
  releases/            # timestamped releases
  shared/.env          # from template below; user fills Supabase creds + APP_KEY
  shared/storage/      # full storage skeleton (app/public, framework/{cache,sessions,views}, logs)
  current -> releases/<...>
```

Plus prints a production `.env` template: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://koszykomat.pl`, `APP_LOCALE=pl`, `DB_CONNECTION=pgsql`, Supabase pooler placeholders (`DB_HOST=aws-0-eu-central-1.pooler.supabase.com`, `DB_PORT=6543`, `DB_SESSION_PORT=5432`, `DB_USERNAME=postgres.<project-ref>`, `DB_PASSWORD=...`, `sslmode=require`), database queue/session/cache, and the `APP_KEY` generation command.

Checklist (`SERVER-SETUP.md`) — panel/dashboard/root steps the script can't do, each with exact clicks/commands:

1. **Supabase project** (dashboard, human-only per infrastructure.md): region Frankfurt (`eu-central-1`), free tier; copy both pooler connection strings (transaction 6543, session 5432) into `shared/.env`. Note the free-tier pause rule: 7 days without queries → project paused, unpause is a manual dashboard step (the future nightly cron is the heartbeat).
2. **SSH for the DA user**: enable in DirectAdmin, key-only; dedicated ed25519 keypair (private half → GitHub secret), `authorized_keys` entry with `no-port-forwarding,no-agent-forwarding,no-X11-forwarding`.
3. **Docroot → `current/public`**: replace `public_html` with a symlink to `./current/public`; ensure `private_html` is a symlink to `public_html` (DA serves HTTPS from `private_html`).
4. **Opcache strategy (root, one-time)**: preferred — custom nginx template using `$realpath_root` for `SCRIPT_FILENAME`/`DOCUMENT_ROOT` in FastCGI params (`/usr/local/directadmin/data/templates/custom/`), zero per-deploy action. Fallback: sudoers entry letting the deploy user `systemctl reload php-fpm*` (release.sh supports both via a variable).
5. **Outbound connectivity check**: `psql`/`php -r` test from the VPS to the Supabase pooler (ports 6543 + 5432 must be open outbound; pooler hosts are IPv4-friendly).
6. **Cron via DirectAdmin's own cron UI** (survives panel rebuilds, per risk register): `* * * * * flock -n /tmp/koszykomat-sched.lock <php> /home/<user>/domains/koszykomat.pl/current/artisan schedule:run`.
7. **LetsEncrypt cert** in DA for koszykomat.pl + www (HTTP-01 passes through CF proxy).
8. **Cloudflare**: SSL/TLS mode **Full (strict)**, "Always Use HTTPS" on. Note: SSH/deploy always targets the IP.
9. **GitHub secrets**: the four listed above.
10. **fail2ban note**: GH Actions IPs rotate; key-only auth produces no failures, but exempt the deploy user if aggressive rules exist.
11. **Recommended follow-up** (not blocking): UptimeRobot on `/up`.

### 5. App code changes (CF-proxied prerequisites)

- `bootstrap/app.php`: trust proxies (`$middleware->trustProxies(at: '*')`) — without this, Laravel behind CF + nginx misdetects scheme → mixed-content/redirect loops.
- `routes/web.php`: tiny `/_version` route returning the `REVISION` file contents (404 if absent). Public SHA on a public repo — not a secret. This is the deploy-verification endpoint demanded by the risk register.
- `CLAUDE.md` gotcha note (per risk register mitigation): web on transaction pooler (6543), migrations/ingestion on session pooler (`pgsql_session`, 5432).

## Explicitly out of scope (deferred to feature work)

- Scheduler entries (nightly ingestion = Supabase heartbeat, `queue:work --stop-when-empty`) in `routes/console.php` — the cron pipe is live after setup, entries land with the features.
- Staging vhost, Socialite/OAuth keys, monitoring setup itself.

## Execution order

1. Write `context/deployment/deploy-plan.md` (this file).
2. App changes: dual DB connections, trust proxies, `/_version` route, CLAUDE.md pooler note (+ `composer lint`, `composer test` via ddev).
3. `deploy/release.sh`, `deploy/setup-server.sh`, `deploy/SERVER-SETUP.md`.
4. `.github/workflows/deploy.yml`.
5. Commit to `main` (conventional commits, small steps), push — first run will fail at the SSH step until the user completes SERVER-SETUP; expected and documented.
6. Hand off: user runs setup script + checklist (Supabase project, `.env`, GitHub secrets), re-runs the workflow (`workflow_dispatch`).

## Verification (end-to-end, after user setup)

1. `gh run watch` on the re-run — green through build → rsync → release (migrate via session pooler) → verify.
2. `curl -fsS https://koszykomat.pl/up` → 200; `curl https://koszykomat.pl/_version` → current SHA.
3. Push a trivial commit → confirm `/_version` changes (proves opcache strategy works).
4. Rollback drill (user, documented in SERVER-SETUP.md): point `current` at previous release, reload, confirm `/_version` shows old SHA — then re-deploy.
5. `ssh <user>@46.29.21.135 'php .../current/artisan schedule:list'` — scheduler reachable (empty for now); confirm sessions/cache tables exist in Supabase dashboard (proves app↔DB connectivity through the pooler).
