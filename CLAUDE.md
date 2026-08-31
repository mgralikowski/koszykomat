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

## 10xDevs AI Toolkit - Module 3, Lesson 3

Lesson 3 is about **hooks** — turning the quality gates from Lesson 1 and the tests from Lesson 2 into automatic, deterministic checks that fire while the agent works. A hook runs outside the model, so it survives context compression, instruction changes, and the model "forgetting". The payoff for agentic hooks specifically: a `PostToolUse` check can feed its result back into the agent's context, so the agent fixes trivial errors (formatting, a missing import, a wrong type) on its own in the next iteration instead of you discovering them minutes later.

```
context/foundation/test-plan.md  (§4 Quality Gates: which check, required when)
        │
        ▼  (assign each gate to the cheapest layer that still gives signal)
   per-edit (agent hooks)  →  pre-commit (git hooks)  →  pre-push  →  CI
        │ lint, format, scoped tests          │ staged       │ heavier    │ integration
        ▼
   exit code + stdout  →  additionalContext  →  agent reacts next turn
```

### Task Router — Which layer for this check

| You want to | Do this |
| --- | --- |
| React the instant the agent edits a file | A per-edit hook (`PostToolUse` matcher `Write\|Edit` in Claude Code). Right for fast checks: lint/format, and scoped tests on risk-area files. This is the **only** layer that can hand feedback to the agent mid-session. |
| Run only the tests that depend on the edited file | Parse the path from the hook's stdin (`jq -r .tool_input.file_path`) and run your runner's related-tests mode (`vitest related "$FILE" --run`, `jest --findRelatedTests $FILE`). Gate it on whether the file is a risk area in `test-plan.md`; don't run tests on every helper or config edit. |
| Catch changes that bypassed the agent (manual edits, a teammate's commit) | A pre-commit git hook (Lefthook or Husky+lint-staged) over staged files: lint + typecheck, and tests on staged risk files. |
| Run heavier checks before code leaves the machine | Pre-push: full typecheck or a broader test set. Anything too slow for per-edit moves here. |
| Decide where a given gate belongs | Ask: is it fast enough (a few seconds) for per-edit, or should it wait for commit/push/CI? Slow checks block the agent loop on every edit — push them up a layer. |
| Use the same hook across tools | The trigger → matcher → handler → signal pattern is the same in Cursor, Codex, Windsurf, and Copilot; only the config file and event names change. See the cross-tool table below. |

### Hook lifecycle — the universal pattern

Every tool's hooks follow four steps:

1. **Trigger** — an event in the tool (e.g. the agent just saved a file: `PostToolUse`).
2. **Matcher** — a filter deciding whether this hook runs (tool name like `Write`/`Edit`, file type, or a name pattern).
3. **Handler** — the action that runs, usually a shell command.
4. **Signal** — the result returns to the tool. The exit code says pass/fail; stdout can flow into the agent's context as feedback.

### Exit codes and the feedback loop

- **0** — success; the hook passed, continue.
- **2** — blocking error; the agent sees the feedback and should react.
- **anything else** — non-blocking error; logged, but does not interrupt work.

On a blocking failure, stdout flows into the agent's context (in Claude Code via `additionalContext`, capped at 10,000 characters; other tools have similar mechanisms with their own limits). That is why the agent can self-correct: it sees the concrete message — missing type, unimported module, badly formatted line — not just "something failed".

The boundary: the agent reliably fixes **trivial** corrections on its own. When a test fails because of wrong business logic, the hook surfaces it but the agent may not diagnose the real cause — it says "something is off" and tries a trivial fix. If that does not resolve in one or two tries, the signal comes back to you, and the problem may deserve its own change-id with the full `/10x-new → /10x-research → /10x-plan → /10x-implement` workflow.

### Three local layers (plus CI)

| Layer | Catches | Timing |
| --- | --- | --- |
| Per-edit (agent hooks) | Formatting, simple type errors, failing unit tests on risk files. Only layer that feeds the agent mid-work. | ms–s |
| Pre-commit (git hooks) | What slipped past per-edit: manual edits, files changed outside the hook, checks too slow for per-edit. Operates on staged files. | s |
| Pre-push | Heavier checks before pushing to remote (full typecheck, broader test set). | s–min |
| CI | Integration problems, cross-module dependencies, checks needing infra unavailable locally. | min |

Local layers do **not** replace CI — CI stays the key verification for shared repo state and environments you don't control. But each local layer that catches an error is one fewer CI round-trip. You don't need all layers from day one: start with one per-edit hook (lint) and one commit gate, add layers as you see what escapes. The quality gates in `test-plan.md §4` decide which checks are worth automating and when; a plan may legitimately defer per-edit hooks if the cost/signal ratio isn't there yet.

### Key rules

- Keep per-edit hooks fast. If a check takes more than a few seconds, move it to commit, push, or CI — a slow per-edit hook blocks the agent loop on every edit. Lint/format are ideal per-edit; full typecheck is often a commit gate in larger projects.
- Run scoped tests, not the whole suite, per edit — only tests related to the edited file, and only when that file is a risk area in `test-plan.md`.
- `related` is a subcommand, not a flag (`vitest related`, not `--related`). Use `--run` so the hook terminates instead of entering watch mode.
- `PostToolUse` fires once per tool use; three edits in one turn fire it three times independently — there is no built-in aggregation.
- The git hook tool (Lefthook vs Husky+lint-staged) is an implementation detail; the rule is the same — run checks on staged files before commit. If Husky already works, don't migrate.
- **Context injection is not universal.** Claude Code, Cursor, Codex, and Copilot (in VS Code) can pass a hook's result to the agent; Windsurf cannot — it can block (exit 2) but can't tell the agent what went wrong.

### The same pattern in every tool

| Tool | Events | Handlers | Context injection | Config |
| --- | --- | --- | --- | --- |
| Claude Code | ~30 | command, http, mcp_tool, prompt, agent | yes | `.claude/settings.json` |
| Cursor | ~18 | command, prompt | yes | `.cursor/hooks.json` |
| Codex | 10 | command | yes | `.codex/hooks.json` |
| Windsurf | 12 | command | **no** | `.windsurf/hooks.json` |
| Copilot | ~13 | command, http, prompt | yes (VS Code) | `.github/hooks/*.json` |

### Lesson boundaries

- This lesson configures hooks and local quality layers only. The hook JSON, `lefthook.yml`, and the per-edit/commit/push layering are the scope.
- Do not write E2E tests, configure Playwright/MCP, or run browser scenarios. That is Lesson 4.
- Do not run the bug-to-fix-to-regression-test debugging workflow. That is Lesson 5.
- Do not change the risk strategy or quality-gate definitions. That is Lesson 1 (`/10x-test-plan`); read current state with `/10x-test-plan --status`.
- Do not write unit/integration test code from scratch here. That is Lesson 2 — hooks only *run* the tests those lessons produced.
- Do not author CI/CD pipelines. That is Module 1 Lesson 5 / Module 2 Lesson 5; hooks are the local layers in front of CI.

### Paths used by this lesson

- `.claude/settings.json` — hook configuration (`~/.claude/settings.json` global, `.claude/settings.json` project, `.claude/settings.local.json` local overrides). Other tools use their own config file (see the table).
- `lefthook.yml` — pre-commit git hook config (lint + typecheck + tests on `{staged_files}`).
- `context/foundation/test-plan.md` — §4 quality gates decide which checks to automate and at which layer; risk areas decide which edits warrant scoped tests.

<!-- END @przeprogramowani/10x-cli -->
