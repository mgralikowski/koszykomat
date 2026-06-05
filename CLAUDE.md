# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Koszykomat — price-comparison web app for Polish supermarket chains (Lidl vs Biedronka). Laravel 13, PHP 8.3, MySQL 5.7, Tailwind CSS 4 + Vite 8, Blade views. Solo MVP project.

Product and stack decisions live in `context/foundation/`:
- Requirements: @context/foundation/prd.md
- Stack rationale: @context/foundation/tech-stack.md

## Commands — always via ddev

All PHP, Composer, npm, and artisan commands run inside ddev containers. Never run PHP on the host.

```bash
ddev start                      # start containers (nginx-fpm, PHP 8.3, MySQL 5.7)
ddev artisan migrate            # any artisan command
ddev composer test              # run tests (clears config, then phpunit)
ddev artisan test tests/Feature/SomeTest.php   # single test file
ddev npm run dev                # Vite dev server
ddev npm run build              # production frontend build
ddev composer lint              # check code style (Pint, Laravel preset)
ddev composer fix               # auto-fix code style
```

App URL: https://koszykomat.ddev.site

## Hard constraints

- **MySQL 5.7** (matches production server) — do not use MySQL 8-only features in migrations or queries (e.g. CHECK constraints enforced, window functions, CTEs, functional indexes).
- **PHP 8.3** — composer platform is pinned; don't require packages needing newer PHP.
- Production is a classic DirectAdmin VPS (nginx + PHP-FPM + cron), not containerized — ddev is local-only.

## Language

- User-facing strings (Blade views, validation messages, emails): **Polish**.
- Code, comments, commit messages, docs: **English**.

## Conventions

- Testing: optional during MVP — but cover tricky logic (especially promo price calculations: 1+1, "second for 1 PLN", loyalty-card prices) with PHPUnit tests. Tests use in-memory SQLite.
- Git: solo project, small commits straight to `main`, conventional-commit style messages.
- Auth per PRD is OAuth-only (no email+password) — Laravel Socialite is planned but not yet installed.

## Gotchas

- Tailwind v4 is wired through the Vite plugin (`@tailwindcss/vite`), not PostCSS — no `tailwind.config.js`; theme customization goes in `resources/css/app.css` via `@theme`.
