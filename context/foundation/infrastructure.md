---
project: koszykomat
researched_at: 2026-06-05
recommended_platform: DirectAdmin VPS (compute) + Supabase (database)
runner_up: Laravel Cloud
context_type: mvp
tech_stack:
  language: PHP 8.3
  framework: Laravel 13
  runtime: nginx + PHP-FPM + cron (classic server), PostgreSQL 17 (Supabase, Frankfurt)
---

## Recommendation

**Deploy compute on the existing DirectAdmin-managed dedicated server (VPS); host the database on Supabase (managed PostgreSQL 17, Frankfurt, free tier).**

The decision is cost-driven and constraint-aligned: the interview set "minimize cost" as the top priority with an explicit bar — a managed platform wins only if it runs the full stack (web + scheduler + queue worker + database) **free during MVP**. The 2026-06-05 research sweep found no platform that clears that bar: PlanetScale's free tier is gone (Apr 2024), Railway offers only a one-time $5 trial, Laravel Cloud is $5/mo + usage after a 30-day trial, Render's free tier cold-starts (~1 min) violate the PRD's <2s requirement, and Koyeb's free tier cannot run separate worker/cron processes. The VPS runs all compute natively at **zero marginal cost** (PHP 8.3 / cron), and is the platform the developer already operates daily. The database is the one piece deliberately moved off the VPS: Supabase's free tier provides a **current PostgreSQL release (17), which the server does not offer**, plus managed backups and a dashboard — at $0 within free-tier limits (500 MB DB, 5 GB egress; checked 2026-06-05). Traffic is single-region (Poland), so edge platforms earn nothing here; Supabase's Frankfurt region is the closest fit.

## Platform Comparison

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP / Integration | Full-stack cost/mo |
|---|---|---|---|---|---|---|
| **DirectAdmin VPS + Supabase DB** | Partial | Fail (compute) / Pass (DB) | Partial | Partial→Pass (once scripted) | Fail (SSH suffices) + Supabase CLI/MCP | **0 zł marginal** |
| **Laravel Cloud** | Pass | Pass | Pass | Pass | Partial | $5 + usage (trial 30 days) |
| **Railway** | Pass | Pass (managed DB) | Pass | Pass | Pass (best-in-class, GA) | ~$10–20, no free tier |
| **Fly.io** | Pass | Partial (DB self-managed; managed offering waitlist-only) | Partial (no llms.txt) | Pass | Partial (experimental) | ~$7–12 + self-op DB |
| **Render** | Pass | Partial (Docker-only PHP) | Pass | Pass | Pass (GA 2025-08) | ~$22–25 |
| Cloudflare | Pass | Pass | Pass (llms.txt) | Pass | Pass | — dropped: no PHP runtime (Containers GA 2026-04 but paid-only); D1 = SQLite, Hyperdrive only proxies an external DB |
| Vercel | Pass | Partial | Pass | Pass | Partial (preview) | — dropped: PHP via community runtime only, no queue workers, cron 1×/day on free |
| Netlify | Pass | Pass | Pass | Pass | Pass | — dropped: no PHP runtime at all (hard filter) |
| Koyeb (free-sweep finding) | Pass | Partial | Pass | Pass | — | — dropped: free tier is web-service-only; no worker/cron processes |

**Hard filters applied**: PHP runtime requirement dropped Netlify outright and effectively dropped Vercel (community runtime, read-only FS, no workers) and Cloudflare Workers (Containers path adds cost + complexity, no bundled relational DB). Interview Q1 (no persistent connections needed) removed no one — queues run cron-driven.

**Soft weights applied**: cost priority (Q2) is decisive — it penalizes every paid platform against a zero-marginal-cost incumbent. Familiarity (Q3: "own VPS, practically free") reinforces the incumbent. Single-region Poland (Q4) neutralizes edge-native advantages. External providers acceptable (Q5) is where the database decision landed: candidates like TiDB Serverless (distributed-DB semantics, needs `colopl/laravel-tidb`) and Aiven (cut to 1 GB as of 2025-05-15) were weaker fits; **Supabase won the database slot** — managed PostgreSQL 17 in Frankfurt, free tier (500 MB / 5 GB egress), first-party CLI and MCP server, while all compute (web + scheduler + worker) stays free on the VPS.

### Shortlisted Platforms

#### 1. DirectAdmin VPS + Supabase database (Recommended)

The VPS runs all compute natively — nginx + PHP-FPM 8.3, system cron for the Laravel scheduler, cron-driven queue workers — at zero marginal cost on hardware the developer already pays for and operates; it is the reason the PHP 8.3 pin exists in `tech-stack.md`. The database deliberately does **not** live on the VPS: Supabase provides a current managed PostgreSQL (17) — unavailable on the server — with backups, a dashboard, and agent-friendly tooling (CLI + first-party MCP server), free within tier limits. Weaknesses are the mirror image: compute is unmanaged, deploy tooling is self-built (GitHub Actions → SSH release), agent operability runs over a broad SSH credential instead of a scoped platform token, and the remote DB adds latency plus a free-tier pause rule. Those weaknesses are absorbed into the risk register below rather than disqualifying.

#### 2. Laravel Cloud

The only PaaS purpose-built for exactly this stack: managed compute (scale-to-zero Flex, ~500 ms wake — GA, added 2025), managed databases (including Postgres), managed queues (1 queue / 3 concurrent on Starter), managed scheduler, EU regions (Frankfurt/Ireland/London). First-party CLI and git deploy; marketed explicitly as agent-friendly. Loses solely on cost: $5/mo + usage after a 30-day trial that requires payment details up front (checked 2026-06-05). **This is the designated escape hatch**: if VPS operations start consuming evenings, the app migrates with near-zero architectural change.

#### 3. Railway

Best-in-class agent integration (GA MCP server at mcp.railway.com plus CLI and an agent-skills format), one-click managed databases, EU West (Amsterdam) region, per-service cron (≥5-min granularity, fine for nightly). The decisive gap: there is no recurring free tier (one-time $5 trial; Hobby $5/mo flat + usage ≈ $10–20/mo for web + worker + cron + DB). Beat Fly.io for third place because Fly's managed database offering is waitlist-only (a self-managed database on a volume is the antithesis of "managed" for a solo dev), despite Fly's first-class Laravel docs and Warsaw region.

## Anti-Bias Cross-Check: DirectAdmin VPS + Supabase

### Devil's Advocate — Weaknesses

1. **No isolation on a shared box.** The server hosts many projects; a runaway leaflet-ingestion job (vision-API retry loop, image-processing memory bloat) can starve PHP-FPM for everything on the machine.
2. **The database is remote (Frankfurt).** Every query pays ~20–30 ms RTT over the internet instead of localhost; an N+1 pattern in basket comparison multiplies that against the PRD's <2 s budget, and the nightly ingestion's bulk INSERTs slow down proportionally.
3. **The deploy pipeline is entirely self-built.** GitHub Actions → SSH → symlink-swap release, migrations, queue restart, opcache handling — every edge case (failed migration mid-release, partial rsync) is the developer's, with no platform rollback as a net.
4. **No preview/staging environments.** Promo-mechanics changes are tested in production or not at all; an agent cannot produce a preview URL for review.
5. **Agent access = SSH key, not a scoped token.** SSH grants the whole server, including unrelated projects — the opposite of the minimal-permissions posture unless deliberately scoped (dedicated deploy user, restricted shell).
6. **Supabase free tier pauses after 7 days without database activity** — and unpausing is a manual dashboard action. The app's availability now depends on the nightly cron staying alive, on top of the VPS itself.

### Pre-Mortem — How This Could Fail

Six months in, the nightly ingestion grew heavier — two chains' leaflets, slower vision-API calls, more images on disk. One night a job hung mid-batch; cron fired the next `schedule:run` on top of it because nobody added `withoutOverlapping`, and two overlapping workers saturated PHP-FPM until the whole server — including unrelated client projects — stopped responding. The developer "fixed" it by disabling the crontab entry and forgot to re-enable it; seven days later Supabase quietly paused the free-tier project for inactivity, and the entire app went down until someone clicked unpause in a dashboard. Meanwhile a deploy two weeks earlier had half-failed: the migration ran but opcache kept serving stale code from the previous symlink target, so promo calculations silently used old logic — the "werdykt nie kłamie" guardrail failed invisibly, the one failure the PRD says the product cannot survive. Disk filled with leaflet images nobody rotated; the comparison page crept past the 2-second budget as N+1 queries multiplied the Frankfurt round-trip. Every individual problem was small; the absence of any platform safety net on the compute side made each one a manual incident on a solo developer's evenings.

### Unknown Unknowns

- **opcache + symlink releases**: PHP-FPM caches resolved realpaths — a symlink-swap deploy can keep serving *old code* until FPM reloads. The release script must reload FPM, or nginx must pass `$realpath_root` instead of `$document_root` to FastCGI.
- **DirectAdmin can overwrite crontabs** — cron entries managed outside the panel may be lost on panel rebuilds; the `schedule:run` entry should live where DirectAdmin owns it, paired with `flock`/`withoutOverlapping`.
- **GitHub Actions runner IPs rotate constantly** — fail2ban or firewall allowlisting on the VPS will intermittently block deploys in a way that looks like flaky CI.
- **Queue workers without Supervisor**: classic DirectAdmin boxes often lack process supervision; the cron-only pattern (`queue:work --stop-when-empty` per minute) works but long vision-API jobs need explicit `--timeout`, lock discipline, and overlap protection.
- **TLS renewal is panel-driven** — a failed LetsEncrypt renewal surfaces via users, not platform alerts, unless monitoring is added.
- **Supavisor transaction-mode pooling (port 6543) has different semantics than a direct connection** — session-level features (e.g. long transactions, some prepared-statement patterns) belong on the session pooler (5432); mixing them up produces intermittent, hard-to-reproduce errors only under production load.
- **"Activity" for the free-tier pause means actual database queries** — dashboard visits and cached responses don't count; a healthy-looking app with a dead cron is still counting down to a pause.
- **Free-tier egress (5 GB/mo) is a real ceiling** — chatty queries from the VPS to Frankfurt all count; an unindexed scan in a hot path burns egress as well as time.

## Operational Story

- **Preview deploys**: none natively. MVP posture: a separate `staging.` subdomain/vhost on the same server deployed from a `staging` branch is the cheapest approximation; per-PR previews are out of scope for a solo MVP.
- **Secrets**: production `.env` lives on the server outside the web root, never in the repo; it holds the Supabase connection string (pooler host, DB password) alongside APP_KEY and OAuth keys. Deploy-time secrets (SSH key, host) live in GitHub Actions repository secrets. Rotation = edit `.env` on server (DB password rotated in the Supabase dashboard first) + rotate the Actions secret; the agent never sees production secret values.
- **Rollback**: symlink-swap releases — `ln -sfn /home/<user>/releases/<previous> current && systemctl reload php-fpm` (exact service name per server), under a minute. Caveat: DB migrations do not auto-roll-back; destructive migrations need a manual `php artisan migrate:rollback` decision.
- **Approval**: human-only — anything touching DirectAdmin panel (SSL, vhosts), Supabase dashboard mutations (project pause/unpause/delete, password rotation, destructive SQL), crontab structure changes, `.env` secret rotation. Agent-allowed unattended: triggering the GitHub Actions deploy on merge to `main`, reading logs, running `php artisan` read-only commands (`schedule:list`, `queue:monitor`) via a scoped deploy user, read-only queries via the Supabase CLI/MCP with a scoped token.
- **Logs**: read-only via SSH — `tail -f ~/domains/<domain>/logs/*.log` (nginx), `tail -f current/storage/logs/laravel.log` (app), plus `gh run view --log` for the CI side. No MCP server; the GitHub MCP/`gh` CLI covers the pipeline half.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Runaway ingestion job starves the shared server (other projects down) | Devil's advocate | M | H | `withoutOverlapping()` + `flock` on scheduler entries; `--timeout` and `--max-time` on queue workers; memory_limit on CLI php; nightly job windowed to low-traffic hours |
| Supabase free tier pauses the project after 7 days of DB inactivity — app fully down until manual unpause | Devil's advocate / Research finding | M | H | Nightly ingestion doubles as heartbeat; cron failure alerting must be loud (uptime monitor on a heartbeat endpoint + `emailOutputOnFailure`); unpause is a documented 30-second manual runbook step |
| Remote DB latency (Frankfurt RTT) blows the <2 s comparison budget | Devil's advocate | M | M | Eager-load relations, no N+1 in basket comparison; cache the guest example-basket comparison; connection via pooler; measure with real RTT in staging before launch |
| Half-failed deploy serves stale code via opcache after symlink swap | Pre-mortem / Unknown unknowns | M | H | Release script ends with PHP-FPM reload (or nginx `$realpath_root`); deploy verification step curls a version endpoint and fails CI if mismatch |
| Failed migration mid-release leaves broken release live | Pre-mortem | L | H | Order release script: upload → `migrate --force` → swap symlink only on success; previous release retained for instant rollback |
| Cron overlap double-runs nightly ingestion | Pre-mortem | M | M | `withoutOverlapping()` on the ingestion command; one `schedule:run` crontab entry guarded by `flock` |
| Silent failures: full disk (leaflet images), expired TLS, dead cron counting down to a DB pause | Pre-mortem / Unknown unknowns | M | H | Minimal monitoring from day 1: uptime check (e.g. free UptimeRobot tier), `df` threshold alert in the nightly job; scheduler `emailOutputOnFailure`; DB backups are Supabase-managed |
| DirectAdmin panel rebuild wipes hand-edited crontab | Unknown unknowns | L | H (silent data staleness) | Manage the cron entry through DirectAdmin's own cron UI/API; nightly job writes a heartbeat row checked by the uptime monitor |
| GitHub Actions deploys intermittently blocked by VPS firewall/fail2ban | Unknown unknowns | M | L | Dedicated deploy user with key-only auth exempted from fail2ban; deploy failures are loud in CI, not silent |
| SSH credential over-grants the agent (whole server, all projects) | Devil's advocate | M | M | Dedicated `deploy` user owning only this app's directory tree; key restricted in `authorized_keys` (`command=`, `no-port-forwarding`); destructive ops stay human-only |
| App outgrows Supabase free-tier limits (500 MB DB / 5 GB egress) | Research finding | L (MVP scale) | M | Two chains' weekly leaflet prices fit comfortably in 500 MB; usage visible in the Supabase dashboard; escape hatch is Pro ($25/mo) or moving the DB — Laravel's `pgsql` config is provider-agnostic |
| Transaction-pooler semantics break a session-dependent query under load | Unknown unknowns | L | M | Web requests on the transaction pooler (6543); migrations and the nightly ingestion on the session pooler (5432); documented in CLAUDE.md so the agent defaults correctly |
| Solo-operator bus factor: every incident is manual, after-hours | Pre-mortem | M | M | Keep the Laravel Cloud migration path warm (no VPS-specific architecture: stick to env-config, S3-compatible storage abstraction, database queues; DB already managed/external) so the platform can be swapped under pressure |

## Getting Started

Validated against the actual stack: Laravel 13 scheduler/queue commands, ddev-local workflow, GitHub Actions → SSH release (no platform CLI exists for a classic VPS — the "CLI" is `ssh` + `gh`).

1. **Create the Supabase project** (manual, dashboard): region Frankfurt (`eu-central-1`), free tier. Note the transaction-pooler connection string (port 6543) for web and the session-pooler one (port 5432) for migrations/long jobs. This is a human-only step.
2. **Create a scoped deploy user on the VPS** (via DirectAdmin): owns `~/domains/koszykomat.<tld>/`, key-only SSH auth; add the public key as a GitHub Actions secret (`SSH_PRIVATE_KEY`, plus `DEPLOY_HOST`, `DEPLOY_USER`).
3. **Set up the release layout on the server**: `releases/<timestamp>/`, shared `.env` and `storage/` symlinked into each release, `current` symlink pointing at the live release. Create production `.env` (APP_KEY, Supabase connection string, OAuth keys) by hand — it never enters the repo or CI.
4. **Write the GitHub Actions deploy workflow** (`.github/workflows/deploy.yml`): on push to `main` → `composer install --no-dev` + `npm run build` in CI → rsync the artifact to a new `releases/` dir → over SSH run `php artisan migrate --force` (session pooler), swap `current` symlink, `php artisan config:cache route:cache view:cache`, reload PHP-FPM. Fail the job (no symlink swap) if migrate fails.
5. **Wire cron on the server** (via DirectAdmin's cron UI so panel rebuilds keep it): `* * * * * cd ~/domains/<domain>/current && flock -n /tmp/koszykomat-sched.lock php artisan schedule:run`. The nightly ingestion and queue draining (`queue:work --stop-when-empty --timeout=<n>`) are defined in `routes/console.php` / the scheduler with `withoutOverlapping()`. The nightly job is also the Supabase free-tier heartbeat — its failure alerting is mandatory, not optional.
6. **Verify the loop end-to-end**: push a trivial commit → watch `gh run watch` → curl a `/up` (Laravel health route) and a version endpoint on production → confirm `php artisan schedule:list` over SSH shows the nightly job → test rollback once by re-pointing `current` at the previous release.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration
- CI/CD pipeline setup (the deploy workflow above is a starting sketch, not a pipeline design)
- Production-scale architecture (multi-region, HA, DR)
