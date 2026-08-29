# Price/Promo Data Model + Example-Basket Seed — Implementation Plan

## Overview

Lay down the price/promo data layer that every downstream change consumes: a chain-agnostic schema for retail networks, canonical products with per-network listings, dated leaflets, and price entries that carry one of the four promo mechanics (FR-007) with its parameters. Ship it with a hand-seeded example basket so the north-star slice (S-01, guest homepage comparison) has real data to compute a verdict over, and with integrity tests proving the seed is well-formed.

This is roadmap item **F-01** (`context/foundation/roadmap.md`, "Foundations"). It has no prerequisites and unlocks S-01, S-02, and F-03.

## Current State Analysis

The repository is a bare Laravel 13.14 scaffold with deploy tooling wired and no domain code:

- `database/migrations/` contains only the three default Laravel migrations (`0001_01_01_000000_create_users_table.php`, `..._create_cache_table.php`, `..._create_jobs_table.php`). No products, prices, promotions, leaflets or baskets.
- `app/Models/` contains only `User.php`. `app/Http/Controllers/Controller.php` is the empty base controller. `app/Providers/AppServiceProvider.php` has empty `register()`/`boot()`.
- `database/seeders/DatabaseSeeder.php:19` still seeds the stock `Test User` and nothing else. `database/factories/` has only `UserFactory.php`.
- `routes/web.php` has `/` (renders `welcome`) and the `/_version` deploy-verification route. No domain routes.
- `app/Enums/` does not exist.
- `config/` has no application-specific config file (only framework defaults).

Key constraints discovered:

- **Tests run on in-memory SQLite** — `phpunit.xml:26-27` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, while production and ddev run MySQL 8.0 (`config/database.php:21`, `CLAUDE.md` hard constraints). Migrations must therefore use portable Blueprint calls only: no `$table->enum()` (MySQL-specific DDL that SQLite emulates with a check constraint and which is painful to alter later), no generated columns, no raw MySQL DDL.
- **Laravel 13 attribute-based model configuration is the house style** — `app/Models/User.php:13-14` uses `#[Fillable([...])]` and `#[Hidden([...])]` attributes instead of `protected $fillable`, with casts still declared in a `casts(): array` method (`app/Models/User.php:25`). The available attributes are in `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/` and include `Fillable`, `Hidden`, `Table`, `UseFactory`, `Scope`, `ScopedBy`, `ObservedBy`.
- **Pint uses the default Laravel preset** — no `pint.json` exists; `ddev composer lint` is `pint --test`.
- `utf8mb4` is already the MySQL default (`config/database.php` `charset`), which the Polish product names and promo descriptions require.

### Key Discoveries

- Anonymous-class migrations are the established style: `return new class extends Migration` (`database/migrations/0001_01_01_000000_create_users_table.php:6`).
- `UserFactory` shows the factory convention: `@extends Factory<User>` docblock + `definition(): array` (`database/factories/UserFactory.php:11-25`).
- `config/app.php:85` sets `faker_locale` to `en_US`. The seed data here is **hand-written Polish**, not faker-generated, so this does not need changing — but factories used by later tests must not rely on faker for product names.
- `composer test` clears config before running PHPUnit (`composer.json` `scripts.test`), so `ddev composer test` is the authoritative test gate.

## Desired End State

`ddev artisan migrate:fresh --seed` produces a database containing:

- Two networks (`lidl`, `biedronka`).
- A small set of canonical products, each with exactly one listing per network (so a cross-network comparison is always possible), where the listings carry the chain's own product name, brand and size label — the raw material FR-008 needs to show *what was paired with what*.
- One current leaflet per network with a `valid_from`/`valid_to` window that includes today.
- Price entries covering all five `promo_type` values — `none`, `simple`, `one_plus_one`, `second_for_fixed`, `loyalty_card` — with parameters valid for their type.
- A declarative example basket (product slugs + quantities) readable from `config('koszykomat.example_basket')`, which S-01 will consume.

Verification: `ddev composer test` passes, including a feature test that seeds the database and asserts completeness (every product listed in both networks), promo-parameter validity per type, and that seeded leaflets are valid on today's date. `ddev composer lint` passes.

## What We're NOT Doing

- **No basket persistence.** No `baskets` / `basket_items` tables and no `user_id` ownership columns. The example basket is a config-declared fixture. Real basket persistence lands in S-02/S-03, where the ownership and privacy-NFR requirements are actually known.
- **No promo rule engine and no basket-total calculation.** The four mandatory PHPUnit promo tests from `CLAUDE.md` assert a *computed basket total*, which requires the engine S-01 introduces. This change only proves the mechanics are *representable*; S-01 proves they're *computable*.
- **No leaflet ingestion.** No vision API, no jobs, no CLI refresh command — that is F-03, currently blocked on the vendor decision.
- **No UI.** No Blade views, no controllers, no routes. `routes/web.php` is untouched.
- **No auth.** F-02 is a parallel, independent foundation.
- **No product matching automation.** Pairings are hand-authored in the seed via the canonical-product foreign key; no fuzzy matching, no weight normalization, no substitutes (PRD §Non-Goals).
- **No historical prices.** A price entry belongs to a leaflet and expires with it; no price-history table, no trend tracking.
- **No admin CRUD** for any of these tables.

## Implementation Approach

Model the pairing as structure rather than as a matching table: a canonical `products` row is the comparison unit, and each network's `network_products` row is that chain's concrete listing of it. Cross-network comparison is then a join through the canonical product, and the brand/gramatura difference FR-008 must display is simply the delta between two listing rows. Basket items (later) point at canonical products, so the basket is chain-neutral by construction.

Prices hang off leaflets, never float free: `price_entries.leaflet_id` is NOT NULL, so *every* price — including a plain `none`-type shelf reference price — inherits a from–to validity window. This makes the data-freshness NFR structural rather than something each query must remember, and it reduces the "verdict never lies" guardrail to one uniform question: *does this network product have a non-expired entry?* It also gives F-03 a natural unit of ingestion — a leaflet row plus its entries, replaceable atomically.

The four mechanics live in one table, discriminated by a string-backed PHP enum with explicit parameter columns rather than a JSON blob. Because the parameter matrix (which columns are meaningful for which type) cannot be expressed portably in DDL across MySQL 8 and SQLite, it is enforced in application code — an invariant method on the enum plus a test that walks every seeded row.

## Critical Implementation Details

**Money arithmetic is a forward constraint.** Prices are stored as `DECIMAL(8,2)` and cast with `decimal:2`, which means Eloquent hands back **strings**. PHP will silently coerce those to float in arithmetic, and the conditional mechanics (1+1, second-for-fixed) require averaging/dividing across a required quantity — precisely where binary-float drift produces an off-by-one-grosz basket total and an untrustworthy verdict. No arithmetic happens in *this* change (there is no calculator yet), so the requirement here is only to record it: the `PromoType` enum docblock and the `PriceEntry` model docblock must state that consumers perform money arithmetic via BCMath (`bcadd`/`bcmul`/`bcsub`, scale 2) or by converting to integer grosze at compute time, never with raw `+`/`*` on cast values. S-01 inherits that contract.

**Seed dates must be relative, not literal.** The seeded leaflets must be valid on whatever day the seeder runs, or the integrity test (and S-01's homepage) start failing on a calendar boundary. Anchor the window to `today()` — e.g. `valid_from = today()->startOfWeek()`, `valid_to = today()->endOfWeek()` — rather than hardcoding 2026 dates.

**The seeder must be idempotent.** It will be re-run against a non-fresh database during S-01 development. Use `updateOrCreate`/`upsert` keyed on the natural keys (`networks.slug`, `products.slug`, `network_products` (network_id, product_id)) so re-running does not duplicate rows or throw on the unique indexes.

## Phase 1: Schema, promo enum, and models

### Overview

Create the five tables, the `PromoType` enum that defines the promo-parameter contract, and the five Eloquent models with their relations, casts and validity scopes. After this phase the database can hold the data but contains none.

### Changes Required

#### 1. Promo mechanic enum

**File**: `app/Enums/PromoType.php` (new; `app/Enums/` does not exist yet)

**Intent**: Define the four FR-007 mechanics plus the no-promo case as a single string-backed enum, and make it the home of the promo-parameter contract so both the seeder and (later) the rule engine and ingestion read the same rules.

**Contract**: `enum PromoType: string` with cases `None = 'none'`, `Simple = 'simple'`, `OnePlusOne = 'one_plus_one'`, `SecondForFixed = 'second_for_fixed'`, `LoyaltyCard = 'loyalty_card'`. Case names are English per `CLAUDE.md`; a `label(): string` method returns the Polish user-facing name (`'cena promocyjna'`, `'1+1 gratis'`, `'drugi produkt za'`, `'cena z kartą'`, `'cena regularna'`) for later report rendering.

The parameter contract is the load-bearing part — it is what the tests in Phase 2 assert and what the rule engine will branch on. Expressed as a single method returning which of the three parameter columns must be present and which must be null:

| Case               | `promo_price` | `required_quantity` | `second_item_price` |
| ------------------ | ------------- | ------------------- | ------------------- |
| `None`             | null          | null                | null                |
| `Simple`           | required      | null                | null                |
| `LoyaltyCard`      | required      | null                | null                |
| `OnePlusOne`       | null          | required (= 2)      | required (= 0.00)   |
| `SecondForFixed`   | null          | required (= 2)      | required (> 0.00)   |

Expose this as `requiredParameters(): array` and `forbiddenParameters(): array` (or one `parameterContract(): array` returning both) so a caller can validate a row without duplicating the matrix. Add a class docblock stating the BCMath / integer-grosze arithmetic requirement from Critical Implementation Details.

#### 2. Networks migration

**File**: `database/migrations/<timestamp>_create_networks_table.php`

**Intent**: The retail chain. A table rather than an enum column because the PRD keeps the architecture chain-agnostic for v2 while MVP ships only Lidl and Biedronka.

**Contract**: `networks` — `id`, `slug` (string, unique), `name` (string), `timestamps`.

#### 3. Products migration

**File**: `database/migrations/<timestamp>_create_products_table.php`

**Intent**: The canonical, chain-neutral comparison unit ("mleko 3,2% 1 l"). This is what basket items will point at in S-02, and what joins the two chains' listings together.

**Contract**: `products` — `id`, `slug` (string, unique), `name` (string, Polish nominal description), `timestamps`.

#### 4. Network products migration

**File**: `database/migrations/<timestamp>_create_network_products_table.php`

**Intent**: One chain's concrete listing of a canonical product, carrying the attributes FR-008 must surface so the user can judge comparability themselves.

**Contract**: `network_products` — `id`, `network_id` (foreignId, constrained, cascade on delete), `product_id` (foreignId, constrained, cascade on delete), `name` (string — the name as printed in that chain's leaflet), `brand` (string, nullable — null for unbranded/private-label where the chain prints none), `size_label` (string, nullable — e.g. `'1 l'`, `'500 g'`; a display label, not a normalized quantity, per the no-weight-normalization non-goal), `timestamps`. Unique index on `(network_id, product_id)` — at most one listing per chain per canonical product, which is what the single-nationwide-leaflet-price model implies.

#### 5. Leaflets migration

**File**: `database/migrations/<timestamp>_create_leaflets_table.php`

**Intent**: The dated container every price belongs to. Gives each price its from–to validity window (data-freshness NFR) and gives F-03 an atomic unit of ingestion.

**Contract**: `leaflets` — `id`, `network_id` (foreignId, constrained, cascade on delete), `name` (string, nullable — e.g. the leaflet's own title), `valid_from` (date), `valid_to` (date), `source_type` (string, default `'manual'` — the hook for F-03's graphic-format provider without committing to its shape now), `source_reference` (string, nullable — file name / URL once ingestion exists), `timestamps`. Index on `(network_id, valid_from, valid_to)` to keep the "current leaflet for this chain" lookup cheap.

#### 6. Price entries migration

**File**: `database/migrations/<timestamp>_create_price_entries_table.php`

**Intent**: One priced offer for one chain's product listing inside one leaflet, discriminated by promo mechanic. The table the rule engine reads and ingestion writes.

**Contract**: `price_entries` — `id`, `leaflet_id` (foreignId, constrained, **not nullable**, cascade on delete), `network_product_id` (foreignId, constrained, cascade on delete), `regular_price` (`decimal(8, 2)` — the undiscounted unit price, always present so the forced-overbuy cost and the "no promo" baseline are both computable), `promo_type` (string, default `'none'` — a plain string column, **not** `$table->enum()`, for MySQL/SQLite portability; cast to `PromoType` in the model), `promo_price` (`decimal(8, 2)`, nullable), `required_quantity` (unsignedTinyInteger, nullable), `second_item_price` (`decimal(8, 2)`, nullable), `timestamps`.

Unique index on `(leaflet_id, network_product_id, promo_type)` — this is deliberate: it lets one product in one leaflet carry both a `none` shelf price and a `loyalty_card` price simultaneously (the FR-007 case where a card splits the verdict), while still preventing duplicate rows of the same mechanic. Index on `network_product_id` for the basket lookup path.

Note in a migration comment that the promo-parameter matrix is enforced in application code (`PromoType`) rather than in DDL, because the check constraints required to express it are not portable between MySQL 8 and the in-memory SQLite used by tests.

#### 7. Models

**File**: `app/Models/Network.php`, `app/Models/Product.php`, `app/Models/NetworkProduct.php`, `app/Models/Leaflet.php`, `app/Models/PriceEntry.php` (all new)

**Intent**: Give each table an Eloquent model following the attribute-based house style, with the relations and casts downstream code needs, so S-01 can eager-load a whole comparison in one pass (no N+1, per `CLAUDE.md`).

**Contract**:

- Every model uses `#[Fillable([...])]` attribute style matching `app/Models/User.php:13`, and `HasFactory` with the `/** @use HasFactory<XFactory> */` docblock.
- Relations: `Network` → `hasMany` `networkProducts`, `hasMany` `leaflets`. `Product` → `hasMany` `networkProducts`. `NetworkProduct` → `belongsTo` `network`, `belongsTo` `product`, `hasMany` `priceEntries`. `Leaflet` → `belongsTo` `network`, `hasMany` `priceEntries`. `PriceEntry` → `belongsTo` `leaflet`, `belongsTo` `networkProduct`.
- Casts: `Leaflet::casts()` returns `valid_from` / `valid_to` as `'date'`. `PriceEntry::casts()` returns `promo_type` as `PromoType::class` and `regular_price` / `promo_price` / `second_item_price` as `'decimal:2'`.
- Validity scopes, declared with the Laravel 13 `#[Scope]` attribute (available at `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Scope.php`): a scope on `Leaflet` filtering to leaflets whose window contains a given date (defaulting to today), and a matching scope on `PriceEntry` constraining through its leaflet. These two scopes are the single chokepoint S-04's "no data instead of a stale verdict" guardrail will hang off — name them clearly and keep the date parameter injectable so tests can pin a date.
- `PriceEntry` carries the arithmetic-contract docblock from Critical Implementation Details.

### Success Criteria

#### Automated Verification

- Migrations apply cleanly on MySQL: `ddev artisan migrate:fresh`
- Migrations apply cleanly on in-memory SQLite (the test connection): `ddev composer test`
- Code style passes: `ddev composer lint`
- Models resolve and relations are wired: `ddev artisan tinker --execute="App\Models\PriceEntry::with('leaflet.network','networkProduct.product')->count();"` returns without error

#### Manual Verification

- Inspect the schema (`ddev artisan db:show --counts` / phpMyAdmin) and confirm the unique index on `price_entries (leaflet_id, network_product_id, promo_type)` exists and `leaflet_id` is NOT NULL
- Confirm the `PromoType` parameter matrix in code matches all four FR-007 mechanics as the PRD describes them (including second-for-**grosz**, i.e. `second_item_price = 0.01`, being expressible)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual review was successful before proceeding to Phase 2.

---

## Phase 2: Example-basket fixture, seeder, and integrity tests

### Overview

Declare the example basket, seed a complete dataset that exercises all five promo types across both chains, and prove with tests that the seed is well-formed and currently valid. After this phase S-01 can be planned against real rows.

### Changes Required

#### 1. Example basket configuration

**File**: `config/koszykomat.php` (new — no app-specific config file exists yet)

**Intent**: Make the guest example basket (FR-001) a declarative fixture that both the seeder and S-01 read, so there is one source of truth for "which products, how many" and S-01 never hardcodes product slugs.

**Contract**: Returns an array with an `example_basket` key: a list of entries, each `['product' => '<product-slug>', 'quantity' => <int>]`. Ship at least 3 entries (the PRD's Primary Success Criterion is a 3-product basket) with at least one quantity > 1, since the conditional mechanics only bite at quantity ≥ 2 and a basket where every quantity is 1 would silently fail to exercise the wedge.

#### 2. Model factories

**File**: `database/factories/NetworkFactory.php`, `ProductFactory.php`, `NetworkProductFactory.php`, `LeafletFactory.php`, `PriceEntryFactory.php` (all new)

**Intent**: Give tests — the integrity tests here and the four mandatory promo tests in S-01 — a way to build valid rows without going through the full seeder.

**Contract**: Each follows the `UserFactory` convention (`@extends Factory<Model>` docblock, `definition(): array`). `LeafletFactory` defaults to a window containing today. `PriceEntryFactory` defaults to `promo_type = PromoType::None` with the promo parameter columns null, plus one state per mechanic (`simple()`, `onePlusOne()`, `secondForFixed()`, `loyaltyCard()`) that sets exactly the parameters that mechanic's contract requires — so a test can express "a 1+1 entry" in one call and cannot accidentally build a contract-violating row. Product/brand names are hand-written Polish strings or sequence-based, not faker output (`config/app.php:85` sets `faker_locale` to `en_US`).

#### 3. Example basket seeder

**File**: `database/seeders/ExampleBasketSeeder.php` (new), invoked from `database/seeders/DatabaseSeeder.php`

**Intent**: Hand-seed the dataset the north-star slice runs on: both chains, the canonical products of the example basket with a listing in each chain, one current leaflet per chain, and price entries whose promo types collectively cover all four mechanics plus a plain price.

**Contract**:

- Seeds `networks`: `lidl` → "Lidl", `biedronka` → "Biedronka".
- Seeds one `products` row per entry in `config('koszykomat.example_basket')` — reading the config, so the fixture and the seed cannot drift apart. Product slugs in the config must all exist after seeding.
- Seeds exactly one `network_products` row per (product × network) — completeness is what makes a verdict possible at all; a missing listing must be a deliberate future test case, not an accident of the seed. Give at least one pair a differing `brand` and at least one a differing `size_label`, so S-01 has a real FR-008 difference to render rather than a uniform set where the "brand differs" badge never appears.
- Seeds one `leaflets` row per network with a window containing today (relative dates — see Critical Implementation Details).
- Seeds `price_entries` such that: every `network_products` row has at least one entry; across the dataset all five `PromoType` cases appear; at least one product carries both a `none` and a `loyalty_card` entry in the same leaflet (exercising the composite unique index and the card-splits-the-verdict case); the two chains' prices are chosen so the basket has a non-tied, non-obvious winner — one chain should win some lines and lose others, so S-01's verdict is actually computed rather than trivially true.
- Idempotent via `updateOrCreate` on natural keys (see Critical Implementation Details).
- `DatabaseSeeder::run()` calls it via `$this->call(ExampleBasketSeeder::class)`, keeping the existing `Test User` creation intact.

#### 4. Seed and schema integrity tests

**File**: `tests/Feature/Database/PricePromoSeedTest.php` (new; `tests/Feature/Database/` does not exist yet)

**Intent**: Prove the seed is complete, currently valid, and free of contract-violating promo rows — the failure modes that would otherwise surface as a confusing wrong verdict during S-01 development.

**Contract**: A feature test using `RefreshDatabase` that runs `ExampleBasketSeeder` and asserts:

- Both network slugs exist; every product slug in `config('koszykomat.example_basket')` exists in `products`.
- Every seeded product has exactly one `network_products` row per network (completeness).
- Every `network_products` row has at least one `price_entries` row.
- All five `PromoType` cases are represented across the seeded entries.
- **Promo-parameter validity**: iterate every seeded `price_entries` row and assert its populated/null parameter columns match its `promo_type`'s contract from `PromoType` — this is the test that makes the application-level invariant real, since DDL can't express it.
- Every seeded leaflet's window contains today, and the validity scopes from Phase 1 return the seeded entries when queried for today and return nothing when queried for a date past `valid_to`.
- At least one matched pair differs in `brand` or `size_label` (so the FR-008 difference rendering has something to show).

Optionally add a small unit test for `PromoType`'s contract method itself (`tests/Unit/Enums/PromoTypeTest.php`) if the matrix logic ends up non-trivial.

### Success Criteria

#### Automated Verification

- Full test suite passes: `ddev composer test`
- Fresh migrate + seed succeeds on MySQL: `ddev artisan migrate:fresh --seed`
- Seeder is idempotent — running it twice does not error or duplicate: `ddev artisan db:seed --class=Database\\Seeders\\ExampleBasketSeeder` run a second time succeeds and row counts are unchanged
- Code style passes: `ddev composer lint`

#### Manual Verification

- Inspect the seeded rows (phpMyAdmin or `ddev artisan tinker`) and sanity-check the prices by hand: confirm the two chains' totals for the example basket are close but not tied, and that the promo lines look like something a real leaflet would print
- Confirm each of the four PRD mechanics is present in the data and its parameters read sensibly (1+1 with `second_item_price = 0.00`; second-for-fixed with `1.00` or `0.01`; a loyalty-card price alongside a regular one)
- Confirm Polish product/brand names render correctly (no mojibake) — the utf8mb4 check from `CLAUDE.md` §Gotchas

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual review was successful before the change is considered done.

---

## Testing Strategy

### Unit Tests

- `PromoType` parameter contract: each case reports the correct required/forbidden parameter columns (only if the matrix logic is non-trivial enough to warrant it separately from the feature test).

### Integration Tests

- `tests/Feature/Database/PricePromoSeedTest.php` — migrations + seeder run end-to-end on in-memory SQLite; seed completeness, promo-parameter validity, validity-window scope behaviour (in-window and out-of-window), and the presence of a renderable brand/size difference.

### Deferred to S-01 (not in this change)

The four mandatory promo-mechanic tests required by `CLAUDE.md` — simple promo price, 1+1 gratis, second for 1 PLN/grosz, loyalty-card price — each asserting a **computed basket total**. They require the rule engine S-01 introduces. This change makes them *writable* (schema + factory states + fixture exist); S-01 writes them.

### Manual Testing Steps

1. `ddev artisan migrate:fresh --seed`
2. Inspect `price_entries` joined to `network_products` and `products`; verify every promo type appears and parameters match the mechanic
3. Hand-total the example basket for each chain and confirm the numbers are plausible, close, and not tied
4. Re-run `ddev artisan db:seed --class=Database\\Seeders\\ExampleBasketSeeder` and confirm no duplicates and no errors
5. Confirm Polish characters in product/brand names display correctly

## Performance Considerations

Nothing in this change executes a user-facing query, but the schema determines whether S-01/S-02 can meet the <2 s NFR. Two things matter: the index on `leaflets (network_id, valid_from, valid_to)` keeps "current leaflet per chain" cheap, and the index on `price_entries.network_product_id` keeps the basket lookup cheap. The comparison query S-01 will write is a single join from canonical products → both chains' listings → their valid price entries, eager-loaded — no per-product queries (`CLAUDE.md`: avoid N+1). The dataset here is tiny; the volume unknown from the roadmap (entries per week for two chains) affects only future sizing, not this schema.

## Migration Notes

Greenfield — no existing domain data, so no data migration and no backwards-compatibility surface. All six migrations are additive; rollback is `ddev artisan migrate:rollback` and the cascade-on-delete foreign keys mean dropping in reverse dependency order is clean. Production has no domain tables yet, so the first deploy after this change simply runs the new migrations. Note that production MySQL has **no managed backups** (`CLAUDE.md` §Gotchas) — irrelevant while the only data is a re-runnable seed, but it becomes relevant the moment F-03 writes real ingested data into these tables.

## References

- Roadmap item: `context/foundation/roadmap.md` — F-01 "Price/promo data model + seed fixture"
- PRD requirements: `context/foundation/prd.md` — FR-006, FR-007, FR-008, FR-009, Business Logic, NFR (data-freshness transparency)
- Plan brief: `context/changes/price-promo-data-model-seed/plan-brief.md`
- Model style reference: `app/Models/User.php:13` (attribute-based `#[Fillable]` / `#[Hidden]`, `casts()` method)
- Migration style reference: `database/migrations/0001_01_01_000000_create_users_table.php:6` (anonymous-class migration)
- Factory style reference: `database/factories/UserFactory.php:11`
- Test connection: `phpunit.xml:26-27` (SQLite `:memory:`)
- Available Eloquent attributes: `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Schema, promo enum, and models

#### Automated

- [x] 1.1 Migrations apply cleanly on MySQL: `ddev artisan migrate:fresh` — 200bec5
- [x] 1.2 Migrations apply cleanly on in-memory SQLite: `ddev composer test` — 200bec5
- [x] 1.3 Code style passes: `ddev composer lint` — 200bec5
- [x] 1.4 Models resolve and relations are wired (tinker eager-load smoke check) — 200bec5

#### Manual

- [x] 1.5 Schema inspected — composite unique index present, `price_entries.leaflet_id` NOT NULL — 200bec5
- [x] 1.6 `PromoType` parameter matrix matches all four FR-007 mechanics (incl. second-for-grosz) — 200bec5

### Phase 2: Example-basket fixture, seeder, and integrity tests

#### Automated

- [x] 2.1 Full test suite passes: `ddev composer test` — 80e24fd
- [x] 2.2 Fresh migrate + seed succeeds on MySQL: `ddev artisan migrate:fresh --seed` — 80e24fd
- [x] 2.3 Seeder is idempotent on a second run (no errors, unchanged row counts) — 80e24fd
- [x] 2.4 Code style passes: `ddev composer lint` — 80e24fd

#### Manual

- [x] 2.5 Seeded prices hand-checked — chain totals close but not tied, promo lines plausible — 80e24fd
- [x] 2.6 All four PRD mechanics present in the data with sensible parameters — 80e24fd
- [x] 2.7 Polish product/brand names render without mojibake (utf8mb4 check) — 80e24fd
