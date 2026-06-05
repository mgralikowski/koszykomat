---
bootstrapped_at: 2026-06-05T14:01:07Z
starter_id: laravel
starter_name: Laravel
project_name: koszykomat
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: "null"
---

## Hand-off

```yaml
starter_id: laravel
package_manager: composer
project_name: koszykomat
hints:
  language_family: php
  team_size: solo
  deployment_target: self-host
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: true
  has_background_jobs: true
```

> Solo developer shipping a price-comparison web app in 3 after-hours weeks, with OAuth-only auth, a nightly data-refresh job, and AI-assisted leaflet ingestion. Laravel is the recommended default for a PHP web app and its bootstrapper confidence is verified, so scaffolding will be smooth. The fit is direct: Socialite covers OAuth login, the scheduler plus queues cover the nightly refresh on a server that already provides cron, and the promo-mechanics rule engine is plain testable domain code over Eloquent and MySQL. Leaflet vision processing is not first-class in any starter and lands as an external vision-API integration invoked from queued jobs. Deployment targets the developer's own dedicated server (DirectAdmin-managed, PHP + MySQL + cron), so CI on GitHub Actions auto-deploys on merge via an SSH-based release step rather than a container push. Local development runs in ddev containers (nginx-fpm, PHP 8.3, MySQL 5.7 — matching the server's maximums; composer platform pinned to php 8.3.0) — containerization is local-only; the production target stays a classic server. Payments and realtime are out of scope per the PRD.

**Mid-run correction (user, during this run)**: local development environment switched to ddev (containerized: nginx-fpm). Production deployment target unchanged (`self-host`, classic nginx + PHP + MySQL + cron). Applied: `.ddev/config.yaml` written via `ddev config` (project-type laravel, docroot public, project-name koszykomat); Laravel `.env` switched from the scaffold's SQLite default to ddev's MySQL credentials (`DB_HOST=db`); the `## Why this stack` paragraph above was amended to record the decision. Containers were not started in this run.

**Post-run correction (user, after bootstrap)**: PHP capped at 8.3 and MySQL at 5.7 to match the production server's maximums (initial ddev config used PHP 8.4 / MySQL 8.0). Laravel 13 requires exactly `php: ^8.3`, so 8.3 is the framework minimum — fully compatible. MySQL 5.7 is supported by the framework (legacy pre-8.0 code paths present) but is EOL since 2023-10 and some newer schema-builder features require MySQL 8.0+. `composer.json` `config.platform.php` pinned to `8.3.0` and the lock file re-resolved against it (host PHP is 8.4), so dependency resolution always matches the server. The project directory was also renamed `mvp` → `koszykomat`, which required re-registering the ddev project (`ddev stop --unlist`).

## Pre-scaffold verification

| Signal      | Value   | Severity | Notes                                                              |
| ----------- | ------- | -------- | ------------------------------------------------------------------ |
| npm package | not run | —        | non-JS starter (language_family: php); no npm CLI in cmd_template   |
| GitHub repo | not run | —        | card docs_url (https://laravel.com/docs) is not a GitHub repo URL — no recency signal available |

## Scaffold log

**Resolved invocation**: `composer create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist`
**Strategy**: subdir-then-move
**Exit code**: 0
**Files moved**: 23 (top-level entries: app, artisan, bootstrap, composer.json, composer.lock, config, database, .editorconfig, .env, .env.example, .gitattributes, .gitignore, .npmrc, package.json, phpunit.xml, public, README.md, resources, routes, storage, tests, vendor, vite.config.js)
**Conflicts (.scaffold siblings)**: none
**.gitignore handling**: moved silently (absent in cwd)
**.bootstrap-scaffold cleanup**: deleted

Scaffold details: laravel/laravel v13.8.0 skeleton, laravel/framework v13.14.0, 109 packages installed (including require-dev). Composer post-create scripts ran: `.env` copied from `.env.example`, application key generated, SQLite database created and migrated (3 migrations: users, cache, jobs). Note: the SQLite default was superseded post-scaffold by the ddev correction above (MySQL). A benign local-toolchain warning (`Xdebug requires Zend Engine API version 420220829`) appeared on every PHP invocation; it did not affect the scaffold.

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool for php
**Recommended external tool**: Roave `security-advisories` Composer plugin (prevents installing packages with known advisories) or `local-php-security-checker` (scans composer.lock against the FriendsOfPHP advisory database).

Incidental signal: Composer itself reported "No security vulnerability advisories found" against the resolved lock file during `create-project` (Packagist advisory check). This is not a substitute for a dedicated audit tool but is recorded here for completeness.

## Hints recorded but not acted on

| Hint                    | Value          |
| ----------------------- | -------------- |
| bootstrapper_confidence | verified       |
| quality_override        | false          |
| path_taken              | standard       |
| self_check_answers      | null           |
| team_size               | solo           |
| deployment_target       | self-host      |
| ci_provider             | github-actions |
| ci_default_flow         | auto-deploy-on-merge |
| has_auth                | true           |
| has_payments            | false          |
| has_realtime            | false          |
| has_ai                  | true           |
| has_background_jobs     | true           |

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git init` (if you have not already) to start your own repo history.
- Review any `.scaffold` siblings the conflict policy created and decide which version of each file to keep.
- Address audit findings per your project's risk tolerance — the full breakdown is in this log.
- `ddev start` to bring up the local containers (nginx-fpm, PHP 8.3, MySQL 5.7), then `ddev artisan migrate` to re-run migrations against MySQL.
