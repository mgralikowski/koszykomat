# Koszykomat

Price-comparison web app for Polish supermarket chains. Users build a shopping basket and get a verdict on where the whole basket is actually cheaper — **Lidl vs Biedronka** — with promo mechanics priced in (simple promo price, 1+1 free, second item for 1 PLN/grosz, loyalty-card price). Prices are extracted from store leaflets into a structured database and refreshed automatically every night.

Key product principles:

- The verdict never lies — with incomplete or expired data the app says "no data" instead of guessing.
- Conditional promos are computed from the actual quantity; forced multi-unit purchases are treated as a cost and shown in the report.
- Product matches between chains are always explicit (brand, weight), so the user can judge comparability.

Full product and stack decisions live in [`context/foundation/prd.md`](context/foundation/prd.md) and [`context/foundation/tech-stack.md`](context/foundation/tech-stack.md).

## Tech stack

- **Backend:** Laravel 13, PHP 8.5
- **Database:** MySQL 8.0 (on the DirectAdmin VPS in production)
- **Frontend:** Blade views, Tailwind CSS 4 + Vite 8
- **Auth:** OAuth-only via Laravel Socialite (planned, no email+password)
- **Local environment:** [ddev](https://ddev.readthedocs.io/) (nginx-fpm, PHP 8.5, MySQL 8.0)

## Requirements

Everything runs inside ddev containers — **no PHP, Composer, or Node needed on the host**.

- [Docker](https://docs.docker.com/get-docker/) (or another ddev-supported container runtime)
- [ddev](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/)

## Local development

```bash
git clone <repo-url> koszykomat && cd koszykomat

ddev start                      # start containers
ddev composer install           # PHP dependencies
cp .env.example .env
ddev artisan key:generate
ddev artisan migrate            # run database migrations
ddev npm install                # frontend dependencies
ddev npm run dev                # Vite dev server (hot reload)
```

The app is available at **https://koszykomat.ddev.site**.

### Common commands

```bash
ddev artisan <command>          # any artisan command
ddev composer test              # run tests (clears config, then phpunit)
ddev artisan test tests/Feature/SomeTest.php   # single test file
ddev composer lint              # check code style (Pint, Laravel preset)
ddev composer fix               # auto-fix code style
ddev npm run build              # production frontend build
```

## Conventions

- User-facing strings (Blade views, validation, emails): **Polish**. Code, comments, commits, docs: **English**.
- Conventional-commit style messages, small commits straight to `main` (solo MVP).
- Tests are optional during MVP, except the four promo mechanics — each must have a PHPUnit test asserting the computed basket total (in-memory SQLite).
