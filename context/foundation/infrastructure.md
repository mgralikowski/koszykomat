---
project: koszykomat
researched_at: 2026-06-05
revised_at: 2026-06-17
recommended_platform: DirectAdmin VPS (compute + MySQL 8.0 database)
runner_up: Laravel Cloud
context_type: mvp
tech_stack:
  language: PHP 8.5
  framework: Laravel 13
  runtime: nginx + PHP-FPM + MySQL 8.0 + cron (classic server)
---

> **Revised 2026-06-17**: the VPS was upgraded to **PHP 8.5 + MySQL 8.0**, and the database was consolidated **onto the VPS itself** (created via the DirectAdmin panel) rather than hosted on remote managed Postgres (Supabase). This removed the reason the database was ever moved off-box — the server now offers a current DB engine natively at zero cost — and with it the remote-RTT latency, free-tier pause, egress ceiling, and connection-pooler concerns. The trade-off it introduces is that **backups are now self-managed** (DirectAdmin backups / `mysqldump` cron) instead of provider-managed; this is captured in the risk register below. The compute-side analysis (unmanaged box, self-built deploy, opcache/cron risks) is unchanged.

## Recommendation

**Deploy compute and the database on the existing DirectAdmin-managed dedicated server (VPS): nginx + PHP-FPM 8.5 + MySQL 8.0 + cron, all on one box.**

The decision is cost-driven and constraint-aligned: the interview set "minimize cost" as the top priority with an explicit bar — a managed platform wins only if it runs the full stack (web + scheduler + queue worker + database) **free during MVP**. The 2026-06-05 research sweep found no platform that clears that bar: PlanetScale's free tier is gone (Apr 2024), Railway offers only a one-time $5 trial, Laravel Cloud is $5/mo + usage after a 30-day trial, Render's free tier cold-starts (~1 min) violate the PRD's <2s requirement, and Koyeb's free tier cannot run separate worker/cron processes. The VPS runs the entire stack natively at **zero marginal cost** (PHP 8.5 / MySQL 8.0 / cron), and is the platform the developer already operates daily. Originally (2026-06-05) the database was the one piece moved off-box, onto Supabase, because the server lacked a current DB engine; the 2026-06-17 server upgrade to MySQL 8.0 removed that reason, so the database now lives on the VPS — local to the app (no remote round-trip), no free-tier limits, at the cost of self-managed backups. Traffic is single-region (Poland), so edge platforms earn nothing here.

## Platform Comparison

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP / Integration | Full-stack cost/mo |
|---|---|---|---|---|---|---|
| **DirectAdmin VPS + MySQL 8.0 (on-box)** | Partial | Fail (self-managed compute + DB) | Partial | Partial→Pass (once scripted) | Fail (SSH suffices) | **0 zł marginal** |
| **Laravel Cloud** | Pass | Pass | Pass | Pass | Partial | $5 + usage (trial 30 days) |
| **Railway** | Pass | Pass (managed DB) | Pass | Pass | Pass (best-in-class, GA) | ~$10–20, no free tier |
| **Fly.io** | Pass | Partial (DB self-managed; managed offering waitlist-only) | Partial (no llms.txt) | Pass | Partial (experimental) | ~$7–12 + self-op DB |
| **Render** | Pass | Partial (Docker-only PHP) | Pass | Pass | Pass (GA 2025-08) | ~$22–25 |
| Cloudflare | Pass | Pass | Pass (llms.txt) | Pass | Pass | — dropped: no PHP runtime (Containers GA 2026-04 but paid-only); D1 = SQLite, Hyperdrive only proxies an external DB |
| Vercel | Pass | Partial | Pass | Pass | Partial (preview) | — dropped: PHP via community runtime only, no queue workers, cron 1×/day on free |
| Netlify | Pass | Pass | Pass | Pass | Pass | — dropped: no PHP runtime at all (hard filter) |
| Koyeb (free-sweep finding) | Pass | Partial | Pass | Pass | — | — dropped: free tier is web-service-only; no worker/cron processes |

**Hard filters applied**: PHP runtime requirement dropped Netlify outright and effectively dropped Vercel (community runtime, read-only FS, no workers) and Cloudflare Workers (Containers path adds cost + complexity, no bundled relational DB). Interview Q1 (no persistent connections needed) removed no one — queues run cron-driven.

**Soft weights applied**: cost priority (Q2) is decisive — it penalizes every paid platform against a zero-marginal-cost incumbent. Familiarity (Q3: "own VPS, practically free") reinforces the incumbent. Single-region Poland (Q4) neutralizes edge-native advantages. External providers acceptable (Q5) originally (2026-06-05) sent the database off-box to Supabase, because the server then offered no current DB engine. The 2026-06-17 server upgrade to **MySQL 8.0** removed that constraint: the database now runs **on the VPS itself**, free, local to the app, and the entire stack (web + scheduler + worker + DB) is co-located — the only remaining external dependency is the GitHub Actions deploy pipeline.

### Shortlisted Platforms

#### 1. DirectAdmin VPS + on-box MySQL 8.0 (Recommended)

The VPS runs the entire stack natively — nginx + PHP-FPM 8.5, MySQL 8.0, system cron for the Laravel scheduler, cron-driven queue workers — at zero marginal cost on hardware the developer already pays for and operates; it is the reason `tech-stack.md` requires `php ^8.5`. As of the 2026-06-17 server upgrade the database lives **on the VPS** (MySQL 8.0, created via the DirectAdmin panel), local to the app and free of any tier limits. Weaknesses: both compute and database are unmanaged, deploy tooling is self-built (GitHub Actions → SSH release), agent operability runs over a broad SSH credential instead of a scoped platform token, and there is no managed-backup safety net — backups are a self-managed cron. Those weaknesses are absorbed into the risk register below rather than disqualifying.

#### 2. Laravel Cloud

The only PaaS purpose-built for exactly this stack: managed compute (scale-to-zero Flex, ~500 ms wake — GA, added 2025), managed databases (including Postgres), managed queues (1 queue / 3 concurrent on Starter), managed scheduler, EU regions (Frankfurt/Ireland/London). First-party CLI and git deploy; marketed explicitly as agent-friendly. Loses solely on cost: $5/mo + usage after a 30-day trial that requires payment details up front (checked 2026-06-05). **This is the designated escape hatch**: if VPS operations start consuming evenings, the app migrates with near-zero architectural change.

#### 3. Railway

Best-in-class agent integration (GA MCP server at mcp.railway.com plus CLI and an agent-skills format), one-click managed databases, EU West (Amsterdam) region, per-service cron (≥5-min granularity, fine for nightly). The decisive gap: there is no recurring free tier (one-time $5 trial; Hobby $5/mo flat + usage ≈ $10–20/mo for web + worker + cron + DB). Beat Fly.io for third place because Fly's managed database offering is waitlist-only (a self-managed database on a volume is the antithesis of "managed" for a solo dev), despite Fly's first-class Laravel docs and Warsaw region.

## Anti-Bias Cross-Check: DirectAdmin VPS + on-box MySQL 8.0

### Devil's Advocate — Weaknesses

1. **No isolation on a shared box.** The server hosts many projects; a runaway leaflet-ingestion job (vision-API retry loop, image-processing memory bloat) can starve PHP-FPM — and now the MySQL server too — for everything on the machine.
2. **No managed-backup safety net.** The DB is now on the VPS, so there are no provider-managed snapshots; a disk failure, an accidental `DROP`, or a botched migration loses all price/basket data unless a self-managed `mysqldump`/DirectAdmin backup is running and tested.
3. **The deploy pipeline is entirely self-built.** GitHub Actions → SSH → symlink-swap release, migrations, queue restart, opcache handling — every edge case (failed migration mid-release, partial rsync) is the developer's, with no platform rollback as a net.
4. **No preview/staging environments.** Promo-mechanics changes are tested in production or not at all; an agent cannot produce a preview URL for review.
5. **Agent access = SSH key, not a scoped token.** SSH grants the whole server, including unrelated projects and the database — the opposite of the minimal-permissions posture unless deliberately scoped (dedicated deploy user, restricted shell).
6. **Compute and data now share a single point of failure.** Co-locating the DB removes network latency but also removes the blast-radius separation a managed DB gave: one server outage takes down web *and* data at once.

### Pre-Mortem — How This Could Fail

Six months in, the nightly ingestion grew heavier — two chains' leaflets, slower vision-API calls, more images on disk. One night a job hung mid-batch; cron fired the next `schedule:run` on top of it because nobody added `withoutOverlapping`, and two overlapping workers saturated PHP-FPM and MySQL until the whole server — including unrelated client projects — stopped responding. Meanwhile a deploy two weeks earlier had half-failed: the migration ran but opcache kept serving stale code from the previous symlink target, so promo calculations silently used old logic — the "werdykt nie kłamie" guardrail failed invisibly, the one failure the PRD says the product cannot survive. Disk filled with leaflet images nobody rotated, and because the database now shares that disk, MySQL writes started failing once it was full. Nobody had set up `mysqldump`, so when the developer finally tried to roll back the bad data there was no clean snapshot to restore from. Every individual problem was small; the absence of any platform safety net — now on both compute *and* data — made each one a manual incident on a solo developer's evenings.

### Unknown Unknowns

- **opcache + symlink releases**: PHP-FPM caches resolved realpaths — a symlink-swap deploy can keep serving *old code* until FPM reloads. The release script must reload FPM, or nginx must pass `$realpath_root` instead of `$document_root` to FastCGI.
- **DirectAdmin can overwrite crontabs** — cron entries managed outside the panel may be lost on panel rebuilds; the `schedule:run` entry should live where DirectAdmin owns it, paired with `flock`/`withoutOverlapping`.
- **GitHub Actions runner IPs rotate constantly** — fail2ban or firewall allowlisting on the VPS will intermittently block deploys in a way that looks like flaky CI.
- **Queue workers without Supervisor**: classic DirectAdmin boxes often lack process supervision; the cron-only pattern (`queue:work --stop-when-empty` per minute) works but long vision-API jobs need explicit `--timeout`, lock discipline, and overlap protection.
- **TLS renewal is panel-driven** — a failed LetsEncrypt renewal surfaces via users, not platform alerts, unless monitoring is added.
- **`utf8mb4` vs legacy `utf8`** — MySQL's `utf8` is a 3-byte charset that silently truncates 4-byte characters; Polish product names and emoji in promo copy need `utf8mb4`/`utf8mb4_unicode_ci` on every table (Laravel's default) or comparisons and storage misbehave under production data.
- **DirectAdmin MySQL grants are panel-managed** — the DB user's privileges are set in the panel; a rebuild or a too-narrow grant can leave the app unable to run migrations (DDL) even though reads work, surfacing only on the next deploy.
- **Shared `max_connections` / buffer pool** — MySQL's server-wide limits are tuned for the whole box; a connection leak in one project (or an unbounded queue worker pool) can exhaust connections for koszykomat with no per-database quota to catch it.

## Operational Story

- **Preview deploys**: none natively. MVP posture: a separate `staging.` subdomain/vhost on the same server deployed from a `staging` branch is the cheapest approximation; per-PR previews are out of scope for a solo MVP.
- **Secrets**: production `.env` lives on the server outside the web root, never in the repo; it holds the local MySQL credentials (host `127.0.0.1`, DB name, user, password) alongside APP_KEY and OAuth keys. Deploy-time secrets (SSH key, host) live in GitHub Actions repository secrets. Rotation = edit `.env` on server (DB password rotated in DirectAdmin's MySQL Management first) + rotate the Actions secret; the agent never sees production secret values.
- **Rollback**: symlink-swap releases — `ln -sfn /home/<user>/releases/<previous> current && systemctl reload php-fpm` (exact service name per server), under a minute. Caveat: DB migrations do not auto-roll-back; destructive migrations need a manual `php artisan migrate:rollback` decision.
- **Approval**: human-only — anything touching DirectAdmin panel (SSL, vhosts, MySQL Management: database/user creation, password rotation, destructive SQL), crontab structure changes, `.env` secret rotation. Agent-allowed unattended: triggering the GitHub Actions deploy on merge to `main`, reading logs, running `php artisan` read-only commands (`schedule:list`, `queue:monitor`) via a scoped deploy user.
- **Logs**: read-only via SSH — `tail -f ~/domains/<domain>/logs/*.log` (nginx), `tail -f current/storage/logs/laravel.log` (app), plus `gh run view --log` for the CI side. No MCP server; the GitHub MCP/`gh` CLI covers the pipeline half.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Runaway ingestion job starves the shared server (other projects down) | Devil's advocate | M | H | `withoutOverlapping()` + `flock` on scheduler entries; `--timeout` and `--max-time` on queue workers; memory_limit on CLI php; nightly job windowed to low-traffic hours |
| Data loss: on-box DB has no managed-backup safety net (disk failure, accidental DROP, bad migration) | Devil's advocate | M | H | Automated `mysqldump` (or DirectAdmin account backup) to off-server storage from day 1, with a tested restore; destructive migrations stay a human-reviewed decision |
| Server outage takes down web and database together (co-located, no blast-radius separation) | Devil's advocate | L | H | Single-box risk accepted for MVP; keep the Laravel Cloud / managed-DB escape hatch warm via env-config and the provider-agnostic `mysql` connection |
| Half-failed deploy serves stale code via opcache after symlink swap | Pre-mortem / Unknown unknowns | M | H | Release script ends with PHP-FPM reload (or nginx `$realpath_root`); deploy verification step curls a version endpoint and fails CI if mismatch |
| Failed migration mid-release leaves broken release live | Pre-mortem | L | H | Order release script: upload → `migrate --force` → swap symlink only on success; previous release retained for instant rollback |
| Cron overlap double-runs nightly ingestion | Pre-mortem | M | M | `withoutOverlapping()` on the ingestion command; one `schedule:run` crontab entry guarded by `flock` |
| Silent failures: full disk (leaflet images fill the disk the DB also writes to), expired TLS, dead refresh cron serving stale prices | Pre-mortem / Unknown unknowns | M | H | Minimal monitoring from day 1: uptime check (e.g. free UptimeRobot tier), `df` threshold alert in the nightly job; scheduler `emailOutputOnFailure`; self-managed `mysqldump`/DirectAdmin DB backups |
| DirectAdmin panel rebuild wipes hand-edited crontab | Unknown unknowns | L | H (silent data staleness) | Manage the cron entry through DirectAdmin's own cron UI/API; nightly job writes a heartbeat row checked by the uptime monitor |
| GitHub Actions deploys intermittently blocked by VPS firewall/fail2ban | Unknown unknowns | M | L | Dedicated deploy user with key-only auth exempted from fail2ban; deploy failures are loud in CI, not silent |
| SSH credential over-grants the agent (whole server, all projects) | Devil's advocate | M | M | Dedicated `deploy` user owning only this app's directory tree; key restricted in `authorized_keys` (`command=`, `no-port-forwarding`); destructive ops stay human-only |
| On-box MySQL contends with other projects for the shared disk, connections, and buffer pool | Devil's advocate / Unknown unknowns | M | M | Two chains' weekly leaflet prices stay small; bound queue-worker concurrency and CLI `memory_limit`; monitor disk + `max_connections`; escape hatch is a managed DB — Laravel's `mysql` config is provider-agnostic |
| Charset truncation: legacy `utf8` silently drops 4-byte characters in Polish/emoji strings | Unknown unknowns | L | M | Use `utf8mb4`/`utf8mb4_unicode_ci` on all tables (Laravel default in `config/database.php`); documented in CLAUDE.md so the agent defaults correctly |
| Solo-operator bus factor: every incident is manual, after-hours | Pre-mortem | M | M | Keep the Laravel Cloud migration path warm (no VPS-specific architecture: stick to env-config, S3-compatible storage abstraction, database queues, the provider-agnostic `mysql` connection) so the platform — including a move to a managed DB — can be swapped under pressure |

## Getting Started

Validated against the actual stack: Laravel 13 scheduler/queue commands, ddev-local workflow, GitHub Actions → SSH release (no platform CLI exists for a classic VPS — the "CLI" is `ssh` + `gh`).

1. **Create the MySQL database** (manual, DirectAdmin → Account Manager → MySQL Management): create the database + a dedicated user (DA prefixes both with the account name). Local to the VPS — `DB_HOST=127.0.0.1`, `DB_PORT=3306`, no SSL/pooler. This is a human-only step. Set up backups (`mysqldump` cron / DirectAdmin account backup) before any real data lands.
2. **Create a scoped deploy user on the VPS** (via DirectAdmin): owns `~/domains/koszykomat.<tld>/`, key-only SSH auth; add the public key as a GitHub Actions secret (`SSH_PRIVATE_KEY`, plus `DEPLOY_HOST`, `DEPLOY_USER`).
3. **Set up the release layout on the server**: `releases/<timestamp>/`, shared `.env` and `storage/` symlinked into each release, `current` symlink pointing at the live release. Create production `.env` (APP_KEY, local MySQL credentials, OAuth keys) by hand — it never enters the repo or CI.
4. **Write the GitHub Actions deploy workflow** (`.github/workflows/deploy.yml`): on push to `main` → `composer install --no-dev` + `npm run build` in CI → rsync the artifact to a new `releases/` dir → over SSH run `php artisan migrate --force`, swap `current` symlink, `php artisan config:cache route:cache view:cache`, reload PHP-FPM. Fail the job (no symlink swap) if migrate fails.
5. **Wire cron on the server** (via DirectAdmin's cron UI so panel rebuilds keep it): `* * * * * cd ~/domains/<domain>/current && flock -n /tmp/koszykomat-sched.lock php artisan schedule:run`. The nightly ingestion and queue draining (`queue:work --stop-when-empty --timeout=<n>`) are defined in `routes/console.php` / the scheduler with `withoutOverlapping()`. Its failure alerting is mandatory, not optional — a dead refresh cron silently serves stale prices.
6. **Verify the loop end-to-end**: push a trivial commit → watch `gh run watch` → curl a `/up` (Laravel health route) and a version endpoint on production → confirm `php artisan schedule:list` over SSH shows the nightly job → test rollback once by re-pointing `current` at the previous release.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration
- CI/CD pipeline setup (the deploy workflow above is a starting sketch, not a pipeline design)
- Production-scale architecture (multi-region, HA, DR)
