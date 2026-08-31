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

### The one exception: Playwright runs on the host

E2E is the only toolchain that does **not** go through ddev. The browsers would add
~400 MB to the web image for no benefit, so Playwright runs host-side against the ddev URL:

```bash
npm run e2e                     # host, not ddev — the whole browser suite
npx playwright test --project=mobile   # phone width only
npx playwright-cli open https://koszykomat.ddev.site --headed   # interactive exploration
```

The suite needs the containers up (`ddev start`) and `APP_ENV=local`, because the session it
uses is minted through the local-only `/_test/login` door.

## Hard constraints

- **MySQL 8.0** — production database is MySQL 8.0 on the DirectAdmin VPS, created via the DirectAdmin panel; local ddev runs the same major version. The DB is local to the app server (no remote RTT), but still eager-load relations and avoid N+1 so basket comparison stays within the <2 s budget.
- **PHP 8.5** — `composer.json` requires `php ^8.5`, matching the server, ddev, and CI; don't require packages needing newer PHP.
- Production compute and database are both on a classic DirectAdmin VPS (nginx + PHP-FPM + MySQL + cron), not containerized — ddev is local-only.

## Language

- User-facing strings (Blade views, validation messages, emails): **Polish**.
- Code, comments, commit messages, docs: **English**.

## Conventions

- Testing: optional during MVP — except the five promo mechanics (simple promo price, 1+1 gratis, second for 1 PLN/grosz, loyalty-card price, conditional unit price when buying N): each must have a PHPUnit test asserting the computed basket total. Tests use in-memory SQLite. The fifth was added on 2026-08-30 after the first real Lidl ingestion showed it is that chain's dominant mechanic — see PRD FR-007.
- Git: solo project, small commits straight to `main`, conventional-commit style messages.
- Auth per PRD is OAuth-only (no email+password) — Laravel Socialite is planned but not yet installed.

### Per-edit hooks (`.claude/settings.json` → `.claude/hooks/`)

Every `Write`/`Edit` on a `.php` file fires three checks, all through ddev:

| Hook | Scope | On failure |
|---|---|---|
| `pint.sh` | the edited file | fixes it silently, never blocks |
| `phpstan.sh` | whole project, level 5 | exit 2 — new errors go back into the agent's context |
| `scoped-tests.sh` | tests for the edited file's risk area only | exit 2 with the failing assertion |

Costs ~7 s per edit inside a risk area, ~3 s outside. The risk-area map in
`scoped-tests.sh` mirrors `context/foundation/test-plan.md` §2 — update both together.
`phpstan-baseline.neon` records the 36 errors that predate the hook (11 in `app/Pricing`);
it is a debt ledger, not an approval. Debug a hook with `/hooks`, not by guessing.

## Gotchas

- Tailwind v4 is wired through the Vite plugin (`@tailwindcss/vite`), not PostCSS — no `tailwind.config.js`; theme customization goes in `resources/css/app.css` via `@theme`.
- The production MySQL database lives on the VPS itself — there are **no managed backups**. Schedule backups yourself (DirectAdmin's backup feature or a `mysqldump` cron), or a server failure loses all price/basket data.
- Use `utf8mb4` (already the default in `config/database.php`) for all tables — Polish product names and promo descriptions contain characters that the legacy `utf8` (3-byte) charset cannot store.

<!-- BEGIN @przeprogramowani/10x-cli -->

## 10xDevs AI Toolkit - Module 3, Lesson 4 (E2E Tests)

**For E2E tests, use the `/10x-e2e` skill.** It is the single source of truth
for the workflow — risk → seed test + rules → generate → review against the five
anti-patterns → re-prompt → verify. The skill's `references/` carry the full
rules, anti-patterns, seed pattern, and prompt-template.

A few hard rules that hold even before you invoke the skill:

- **Locators:** `getByRole` / `getByLabel` / `getByText` first; `getByTestId`
  only when accessibility attributes are ambiguous. Never CSS selectors, XPath,
  or DOM structure.
- **Never `page.waitForTimeout()`.** Wait for state: `toBeVisible()`,
  `waitForURL()`, `waitForResponse()`.
- **Test independence + cleanup.** Each test runs standalone — its own setup,
  action, assertion, and cleanup; unique ids (timestamp suffix) so parallel runs
  and re-runs don't collide.

Two boundaries to keep straight:

- **DOM (snapshot) is the default.** Vision (`--caps=vision`) is a supplement for
  visual-only risks (layout, z-index, animation); for pixel regression prefer
  deterministic tools (`toMatchSnapshot`, Argos, Lost Pixel). VLM model
  selection/cost is a debugging topic (Lesson 5), not testing.
- **Healer helps on selectors, harms on logic.** A changed selector → healer
  re-finds it (route through PR review). A changed business behavior → healer
  masks the bug; that failing-test-to-fix case is Lesson 5.

<!-- END @przeprogramowani/10x-cli -->
