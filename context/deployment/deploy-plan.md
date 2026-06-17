# Deploy plan: koszykomat.pl → DirectAdmin VPS (compute + MySQL 8.0)

> Approved Plan Mode artifact (Module 1, Lesson 5). Audit trail of "what was supposed to happen" — consumed downstream by milestone planning as ground truth for what's deployed and which secrets are wired.
>
> **Revised 2026-06-17**: the server was upgraded to **PHP 8.5 + MySQL 8.0**, and the database was consolidated onto the VPS itself (created via the DirectAdmin panel) instead of a remote managed Postgres. This removed the dual connection-pooler split, the `pgsql_session` connection, the remote-RTT latency concern, and the free-tier pause/heartbeat story; it added a self-managed-backups responsibility.

## Context

First production deployment of Koszykomat per `context/foundation/infrastructure.md`: compute **and** database on the own DirectAdmin VPS — **MySQL 8.0, created via the DirectAdmin panel**, local to the app. Already done by the user: domain **koszykomat.pl** bought, DNS on **Cloudflare (proxied / orange cloud)**, domain added to DirectAdmin (server IP **46.29.21.135**), server upgraded to PHP 8.5 + MySQL 8.0; the user creates the MySQL database/user in DirectAdmin and fills production `.env` credentials. Code lives in a **public GitHub repo**. Local repo runs the same engines under ddev (`type: mysql` 8.0, `.env.example` `DB_CONNECTION=mysql`, CLAUDE.md updated).

Decisions confirmed: deploy = **GitHub Actions → rsync → symlink-swap releases** (no Deployer); server-side setup = **script + checklist for the user to run** (the agent does not SSH to the server).

## Repo state (explored at planning time)

- No `.github/workflows/`, no deploy scripts, no `context/deployment/`.
- `composer.json`: requires `php ^8.5`; scripts `test`/`lint`/`fix` exist. Vite 8 + Tailwind 4 build via `npm run build`.
- Health route wired: `bootstrap/app.php:12` → `health: '/up'`.
- `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database` — these now live in the VPS MySQL; no Redis needed.
- Only 3 default migrations; `routes/console.php` has nothing scheduled yet (scheduler entries are feature work — only the cron pipe gets wired now).
- Tests run on in-memory SQLite → CI needs no MySQL service container.

## What gets created in the repo

### 1. Single MySQL connection in `config/database.php` + `.env.example`

The database is local to the app server, so the standard Laravel `mysql` connection is all that's needed — no connection pooler, no SSL, no session/transaction split.

- `mysql` (default, all requests and CLI jobs) → `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `utf8mb4`.
- Locally these are provided by ddev's MySQL 8.0 container; in production they point at the DirectAdmin-created database on the same VPS.
- `.env.example` documents the local defaults and a comment that production credentials come from DirectAdmin's MySQL Management (DB/user prefixed with the account name).

### 2. `.github/workflows/deploy.yml`

Single workflow, `on: push: branches: [main]` + `workflow_dispatch`. Two phases in one job (public repo → free Actions minutes):

**Build (CI):**
- Checkout, `shivammathur/setup-php` with PHP 8.5, Node 22.
- `composer install --no-dev --optimize-autoloader` (`require: php ^8.5` matches the server), `npm ci && npm run build`.
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
2. `php artisan migrate --force` (default `mysql` connection) — non-zero exit aborts the script, `current` untouched, CI goes red.
3. `php artisan config:cache && route:cache && view:cache && storage:link` (run inside the new release dir).
4. Atomic swap: temp-symlink + `mv -T` onto `current`.
5. Opcache flush — see strategy in setup checklist below.
6. Prune old releases, keep last 5.

DirectAdmin PHP binary path (e.g. `/usr/local/php85/bin/php`) parameterized at the top of the script.

### 4. `deploy/setup-server.sh` + `deploy/SERVER-SETUP.md` (one-time, run by user)

Script (run as the DA user over SSH) creates the layout:

```
~/domains/koszykomat.pl/
  releases/            # timestamped releases
  shared/.env          # from template below; user fills MySQL creds + APP_KEY
  shared/storage/      # full storage skeleton (app/public, framework/{cache,sessions,views}, logs)
  current -> releases/<...>
```

Plus prints a production `.env` template: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://koszykomat.pl`, `APP_LOCALE=pl`, `DB_CONNECTION=mysql`, local MySQL placeholders (`DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=<da-user>_koszykomat`, `DB_USERNAME=<da-user>_koszykomat`, `DB_PASSWORD=...`), database queue/session/cache, and the `APP_KEY` generation command.

Checklist (`SERVER-SETUP.md`) — panel/dashboard/root steps the script can't do, each with exact clicks/commands:

1. **MySQL database** (DirectAdmin → Account Manager → MySQL Management, human-only): create the database + dedicated user (DA prefixes both with the account name), copy DB/user/password into `shared/.env`. Note that backups are now self-managed — enable DirectAdmin account backups or a `mysqldump` cron before the app holds real data.
2. **SSH for the DA user**: enable in DirectAdmin, key-only; dedicated ed25519 keypair (private half → GitHub secret), `authorized_keys` entry with `no-port-forwarding,no-agent-forwarding,no-X11-forwarding`.
3. **Docroot → `current/public`**: replace `public_html` with a symlink to `./current/public`; ensure `private_html` is a symlink to `public_html` (DA serves HTTPS from `private_html`).
4. **Opcache strategy (root, one-time)**: preferred — custom nginx template using `$realpath_root` for `SCRIPT_FILENAME`/`DOCUMENT_ROOT` in FastCGI params (`/usr/local/directadmin/data/templates/custom/`), zero per-deploy action. Fallback: sudoers entry letting the deploy user `systemctl reload php-fpm*` (release.sh supports both via a variable).
5. **Database connectivity check**: `mysql -h 127.0.0.1 -u <da-user>_koszykomat -p ... -e 'SELECT VERSION();'` from the VPS — confirms the app can reach the local MySQL with the `.env` credentials (no firewall change needed; the DB is local).
6. **Cron via DirectAdmin's own cron UI** (survives panel rebuilds, per risk register): `* * * * * flock -n /tmp/koszykomat-sched.lock <php> /home/<user>/domains/koszykomat.pl/current/artisan schedule:run`.
7. **LetsEncrypt cert** in DA for koszykomat.pl + www (HTTP-01 passes through CF proxy).
8. **Cloudflare**: SSL/TLS mode **Full (strict)**, "Always Use HTTPS" on. Note: SSH/deploy always targets the IP.
9. **GitHub secrets**: the four listed above.
10. **fail2ban note**: GH Actions IPs rotate; key-only auth produces no failures, but exempt the deploy user if aggressive rules exist.
11. **Recommended follow-up** (not blocking): UptimeRobot on `/up`; database backups.

### 5. App code changes (CF-proxied prerequisites)

- `bootstrap/app.php`: trust proxies (`$middleware->trustProxies(at: '*')`) — without this, Laravel behind CF + nginx misdetects scheme → mixed-content/redirect loops.
- `routes/web.php`: tiny `/_version` route returning the `REVISION` file contents (404 if absent). Public SHA on a public repo — not a secret. This is the deploy-verification endpoint demanded by the risk register.
- `CLAUDE.md` gotcha note (per risk register mitigation): MySQL backups are self-managed; use `utf8mb4` for Polish strings.

## Explicitly out of scope (deferred to feature work)

- Scheduler entries (nightly ingestion, `queue:work --stop-when-empty`) in `routes/console.php` — the cron pipe is live after setup, entries land with the features.
- Staging vhost, Socialite/OAuth keys, monitoring setup itself, database backup automation.

## Execution order

1. Write `context/deployment/deploy-plan.md` (this file).
2. App changes: MySQL connection, trust proxies, `/_version` route, CLAUDE.md note (+ `composer lint`, `composer test` via ddev).
3. `deploy/release.sh`, `deploy/setup-server.sh`, `deploy/SERVER-SETUP.md`.
4. `.github/workflows/deploy.yml`.
5. Commit to `main` (conventional commits, small steps), push — first run will fail at the SSH step until the user completes SERVER-SETUP; expected and documented.
6. Hand off: user runs setup script + checklist (MySQL DB, `.env`, GitHub secrets), re-runs the workflow (`workflow_dispatch`).

## Verification (end-to-end, after user setup)

1. `gh run watch` on the re-run — green through build → rsync → release (migrate) → verify.
2. `curl -fsS https://koszykomat.pl/up` → 200; `curl https://koszykomat.pl/_version` → current SHA.
3. Push a trivial commit → confirm `/_version` changes (proves opcache strategy works).
4. Rollback drill (user, documented in SERVER-SETUP.md): point `current` at previous release, reload, confirm `/_version` shows old SHA — then re-deploy.
5. `ssh <user>@46.29.21.135 'php .../current/artisan schedule:list'` — scheduler reachable (empty for now); confirm sessions/cache tables exist in MySQL (`SHOW TABLES;`) — proves app↔DB connectivity.
