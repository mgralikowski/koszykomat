# Price/Promo Data Model + Example-Basket Seed — Plan Brief

> Full plan: `context/changes/price-promo-data-model-seed/plan.md`
> Roadmap item: `context/foundation/roadmap.md` — F-01

## What & Why

Build the price/promo data layer every downstream change consumes: retail networks, canonical products with per-network listings, dated leaflets, and price entries carrying one of the four promo mechanics with its parameters — plus a hand-seeded example basket. This is roadmap **F-01**: it has no prerequisites and unlocks the north star (S-01 guest comparison), S-02, and F-03 ingestion. The roadmap flags the risk plainly: if the four mechanics can't be represented cleanly as data, both the rule engine and ingestion churn.

## Starting Point

A bare Laravel 13.14 scaffold. `database/migrations/` holds only the three default migrations (users, cache, jobs); `app/Models/` holds only `User.php`; `DatabaseSeeder` still seeds the stock test user; there is no `app/Enums/` and no app-specific config file. Deploy tooling and Tailwind/Vite are wired, but no domain code of any kind exists.

## Desired End State

`ddev artisan migrate:fresh --seed` yields two chains, a handful of canonical products each listed in **both** chains (with that chain's own name, brand and size label), one current leaflet per chain whose window contains today, and price entries covering all five promo types — with the example basket declared in `config('koszykomat.example_basket')` for S-01 to read. `ddev composer test` proves the seed is complete, currently valid, and free of contract-violating promo rows.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Promo mechanic modeling | One `price_entries` table, string-backed `PromoType` enum + explicit nullable parameter columns | The rule engine branches on one enum with typed, queryable parameters, and ingestion writes one row — no JSON blob, no four-table union. |
| Money storage | `DECIMAL(8,2)` with `decimal:2` casts | User's call; readable in the DB and no unit conversion in views — with the compensating requirement that all arithmetic goes through BCMath or integer grosze. |
| Product / cross-chain pairing | Canonical `products` + per-network `network_products` listings | The pairing *is* the foreign key, so FR-008's brand/gramatura difference reads straight off the two listing rows and basket items stay chain-neutral. |
| Validity windows | `leaflets` table with `price_entries.leaflet_id` NOT NULL | Every price — including plain shelf prices — inherits a from–to window, making the freshness NFR structural and reducing the "no data" guardrail to one uniform check. |
| Basket scope | Config-declared fixture; no `baskets` tables | Keeps F-01 minimal and defers basket persistence to S-02/S-03 where ownership and the privacy NFR are actually known. |
| Verification scope | Schema + seed integrity tests here; the four mandatory promo tests in S-01 | Those four tests assert a *computed basket total*, which needs the rule engine S-01 introduces. |

## Scope

**In scope:** `networks`, `products`, `network_products`, `leaflets`, `price_entries` migrations; `PromoType` enum with its parameter contract; five Eloquent models with relations, casts and validity scopes; five factories with per-mechanic states; `config/koszykomat.php` example basket; idempotent `ExampleBasketSeeder`; seed-integrity feature test.

**Out of scope:** basket persistence and ownership (S-02/S-03); the promo rule engine and any basket-total calculation (S-01); leaflet ingestion / vision API / jobs / CLI refresh (F-03); any UI, route or controller; auth (F-02); automated product matching, weight normalization, substitutes; price history; admin CRUD.

## Architecture / Approach

Pairing is modeled as structure, not as a matching table: a canonical `products` row is the comparison unit and each chain's `network_products` row is its concrete listing, so cross-chain comparison is a join through the canonical product and the brand/size delta is just the difference between two rows. Prices never float free — `price_entries.leaflet_id` is NOT NULL, so every price inherits a validity window and F-03 gets a natural atomic unit of ingestion. The promo-parameter matrix (which columns are meaningful for which mechanic) can't be expressed portably across MySQL 8 and the in-memory SQLite used by tests, so it lives in `PromoType` and is enforced by a test that walks every seeded row.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Schema, promo enum, models | Six migrations, `PromoType` + its parameter contract, five models with relations/casts/validity scopes | Getting the promo-parameter matrix wrong — it's the contract S-01 and F-03 both build on, and changing it later means migrating data |
| 2. Fixture, seeder, integrity tests | `config/koszykomat.php` basket, factories with per-mechanic states, idempotent seeder, seed-integrity test | Seed data that technically validates but is useless for S-01 — tied totals, no brand differences, or a basket where every quantity is 1 so no conditional mechanic ever fires |

**Prerequisites:** none — F-01 sits at the root of the dependency graph. A working `ddev` environment (`ddev start`) is all that's needed.
**Estimated effort:** ~1–2 sessions across 2 phases; the schema is small and there is no UI or external integration.

## Open Risks & Assumptions

- **DECIMAL + PHP arithmetic.** `decimal:2` casts return strings that silently coerce to float; the conditional mechanics require averaging across a required quantity, exactly where drift produces an off-by-one-grosz total and an untrustworthy verdict. No arithmetic happens in this change, so the mitigation is a recorded contract (BCMath scale 2, or integer grosze at compute time) that S-01 must honor.
- **One listing per chain per product.** The unique index on `network_products (network_id, product_id)` assumes a single nationwide leaflet price with no variants — consistent with the PRD non-goals, but it would need revisiting if a chain lists two comparable variants.
- **Relative seed dates.** Leaflet windows are anchored to `today()`; hardcoded dates would make the integrity test and S-01's homepage fail on a calendar boundary.
- **Data volume unknown** (roadmap Open Question 2) — affects future sizing only, not this minimal schema.

## Success Criteria (Summary)

- A fresh `migrate:fresh --seed` produces a dataset where both chains can be compared for every example-basket product, all four promo mechanics appear with valid parameters, and every price carries a window that includes today.
- `ddev composer test` and `ddev composer lint` pass; the seeder can be re-run without duplicating rows.
- S-01 can be planned and implemented directly against this schema and fixture, with no further data-layer work needed to compute a verdict.
