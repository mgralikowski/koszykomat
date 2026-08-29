# CLAUDE.md

## Project

Koszykomat — price-comparison web app for Polish supermarket chains (Lidl vs Biedronka). Laravel 13, PHP 8.5, MySQL 8.0, Tailwind CSS 4 + Vite 8, Blade views. Solo MVP project.

Product and stack decisions live in `context/foundation/`:
- Requirements: @context/foundation/prd.md
- Stack rationale: @context/foundation/tech-stack.md

## Commands — always via ddev

All PHP, Composer, npm, and artisan commands run inside ddev containers. Never run PHP on the host.

```bash
ddev start                      # start containers (nginx-fpm, PHP 8.5, MySQL 8.0)
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

- **MySQL 8.0** — production database is MySQL 8.0 on the DirectAdmin VPS, created via the DirectAdmin panel; local ddev runs the same major version. The DB is local to the app server (no remote RTT), but still eager-load relations and avoid N+1 so basket comparison stays within the <2 s budget.
- **PHP 8.5** — `composer.json` requires `php ^8.5`, matching the server, ddev, and CI; don't require packages needing newer PHP.
- Production compute and database are both on a classic DirectAdmin VPS (nginx + PHP-FPM + MySQL + cron), not containerized — ddev is local-only.

## Language

- User-facing strings (Blade views, validation messages, emails): **Polish**.
- Code, comments, commit messages, docs: **English**.

## Conventions

- Testing: optional during MVP — except the four promo mechanics (simple promo price, 1+1 gratis, second for 1 PLN/grosz, loyalty-card price): each must have a PHPUnit test asserting the computed basket total. Tests use in-memory SQLite.
- Git: solo project, small commits straight to `main`, conventional-commit style messages.
- Auth per PRD is OAuth-only (no email+password) — Laravel Socialite is planned but not yet installed.

## Gotchas

- Tailwind v4 is wired through the Vite plugin (`@tailwindcss/vite`), not PostCSS — no `tailwind.config.js`; theme customization goes in `resources/css/app.css` via `@theme`.
- The production MySQL database lives on the VPS itself — there are **no managed backups**. Schedule backups yourself (DirectAdmin's backup feature or a `mysqldump` cron), or a server failure loses all price/basket data.
- Use `utf8mb4` (already the default in `config/database.php`) for all tables — Polish product names and promo descriptions contain characters that the legacy `utf8` (3-byte) charset cannot store.

<!-- BEGIN @przeprogramowani/10x-cli -->

## 10xDevs AI Toolkit - Module 2, Lesson 3

Review AI-generated code before merge with the **implementation review chain**:

```
/10x-implement -> /10x-impl-review -> triage -> (/10x-lesson | fix | skip | disagree)
```

`/10x-impl-review` is the lesson focus. Review is a quality gate, not an instruction to fix every finding.

### Task Router - Where to start

| Skill | Use it when |
| --- | --- |
| **Code review (lesson focus)** | |
| `/10x-impl-review <change-id>` | You have implemented code and want a structured review before merge. The skill checks plan adherence, scope discipline, safety and quality, architecture, pattern consistency, and success criteria, then presents findings for triage. |
| **Recurring lesson outcome** | |
| `/10x-lesson` | A finding reveals a recurring project rule or agent failure pattern. Record it in `context/foundation/lessons.md` instead of treating it as a one-off note. |

### Triage discipline

- Severity says how bad the finding is. Impact says how much the decision matters now.
- Valid outcomes: fix now, fix differently, skip, accept as risk, record as recurring rule (`/10x-lesson`), disagree.
- Fix critical findings. Do not burn hours on low-impact observations just because the agent found them.
- Conscious skipping of low-impact findings is a valid review outcome, not negligence.
- If you disagree with a finding, record why. Wrong agent reasoning is also signal.

### Review boundaries

- This lesson reviews implemented code. It does not create the plan, execute new phases, or teach CI review.
- Testing strategy and quality gates are introduced in Module 3.
- Do not use `/10x-contract` as a triage outcome in this lesson.

### Paths used by this lesson

- `context/changes/<change-id>/plan.md` - expected implementation contract
- `context/changes/<change-id>/reviews/` - review output
- `context/foundation/lessons.md` - recurring lessons

Skills must not write to `context/archive/`. Archived changes are immutable; if a resolved target path starts with `context/archive/`, abort with: "This change is archived. Open a new change with `/10x-new` instead."

<!-- END @przeprogramowani/10x-cli -->
