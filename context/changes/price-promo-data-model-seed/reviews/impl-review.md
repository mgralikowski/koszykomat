<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Price/Promo Data Model + Example-Basket Seed

- **Plan**: `context/changes/price-promo-data-model-seed/plan.md`
- **Scope**: Phase 1 and Phase 2 of 2 (full plan — all 13 Progress items `[x]`)
- **Date**: 2026-07-25
- **Verdict**: NEEDS ATTENTION
- **Triage**: complete (2026-08-29) — 5 fixed, 4 skipped, 1 deferred to F-03, 1 declined
- **Findings**: 0 critical, 5 warnings, 5 observations

## Verification performed

| Criterion | Command | Result |
|---|---|---|
| 1.1 / 2.2 Migrations + seed on MySQL | `ddev artisan migrate:fresh --seed` | PASS — 5 domain migrations applied, seeder DONE |
| 1.2 / 2.1 Test suite (in-memory SQLite) | `ddev composer test` | PASS — 15 tests, 79 assertions, 0.86s |
| 1.3 / 2.4 Code style | `ddev composer lint` | PASS — 45 files |
| 1.4 Models resolve, relations wired | `tinker` eager-load `PriceEntry::with('leaflet.network','networkProduct.product')->count()` | PASS — returns 9 |
| 2.3 Seeder idempotent | `ddev artisan db:seed --class=…\ExampleBasketSeeder --force` run twice | PASS — counts unchanged `{networks:2, products:4, network_products:8, leaflets:2, price_entries:9}` |

Manual items 1.5, 1.6, 2.5, 2.6, 2.7 are all marked `[x]` in Progress. Independently corroborated from the diff and the live DB: composite unique index and `leaflet_id NOT NULL` present (`price_entries` migration `:45`, `:29`); all four FR-007 mechanics present with second-for-grosz expressible; hand-totalled example basket is Lidl 62.43 vs Biedronka 67.46 with each chain winning some lines; Polish names store and read back without mojibake. No rubber-stamping detected. One caveat on 1.6 — see F4.

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — `validOn()` returns nothing on the last valid day when given a datetime string

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Models/Leaflet.php:61-66` (and `app/Models/PriceEntry.php:80-83`, which delegates)
- **Detail**: The signature accepts `DateTimeInterface|string|null`, but `whereDate()` only reformats `DateTimeInterface` values — a string binding passes through verbatim. Verified against the live DB (leaflet window `2026-07-20 … 2026-07-26`):

  ```
  validOn(Carbon::parse('2026-07-26'))   → 2
  validOn('2026-07-26')                  → 2
  validOn('2026-07-26 12:00:00')         → 0   ← wrong
  PriceEntry::validOn('2026-07-26 12:00:00') → 0
  ```

  SQL becomes `date(valid_to) >= '2026-07-26 12:00:00'` → `'2026-07-26' >= '2026-07-26 12:00:00'` → false. A caller passing `now()->toDateTimeString()` — natural, and the signature invites it — gets a false "brak danych" on the final valid day of every leaflet. That is the S-04 guardrail chokepoint the plan named (`plan.md:156`) failing in the direction that silently kills the homepage. Tests only ever pass Carbon instances, so it is invisible.

  Same line carries a second cost: `whereDate()` wraps the column in `date()`/`strftime()`, making the predicate non-sargable. `EXPLAIN` on the seeded MySQL shows the `leaflets_network_id_valid_from_valid_to_index` added by migration `120004:30` is only half-used — `type=ref, key_len=8` (network_id only) with `whereDate`, versus `type=range, key_len=11` with a plain `where`. Irrelevant at 2 rows, but this is the query on the <2 s basket-comparison path and the index exists specifically for it.
- **Fix A ⭐ Recommended**: Normalize the argument then use plain `where()` — `$date = Carbon::parse($date ?? today())->startOfDay();` followed by `where('valid_from','<=',$date)->where('valid_to','>=',$date)`.
  - Strength: Fixes correctness and index usage in one edit, keeps the flexible signature the plan asked for ("keep the date parameter injectable so tests can pin a date"), and makes the SQL sargable so the composite index is actually reachable.
  - Tradeoff: `Carbon::parse` throws on malformed input rather than silently returning nothing — arguably the better failure mode, but it is a behaviour change.
  - Confidence: HIGH — both the wrong-result and the `EXPLAIN` difference were reproduced directly against MySQL and SQLite.
  - Blind spot: No caller exists yet outside tests, so nothing depends on the current behaviour.
- **Fix B**: Narrow the signature to `DateTimeInterface|null` and leave `whereDate()` in place.
  - Strength: Smallest possible change; makes the unsupported input a type error at the call site.
  - Tradeoff: Leaves the non-sargable index problem entirely unfixed, and pushes normalization onto every future caller.
  - Confidence: MEDIUM — closes the correctness hole but not the performance one.
  - Blind spot: Ingestion (F-03) may want to pass strings parsed from leaflet metadata.
- **Decision**: FIXED in `fbef5d7` — Fix A. Normalized with `startOfDay()` and switched to plain `where()`; regression test in `tests/Feature/Database/LeafletValidityScopeTest.php`.

### F2 — Seeded leaflet window expires on Monday; nothing re-seeds

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `database/seeders/ExampleBasketSeeder.php:137-138` (and `database/factories/LeafletFactory.php:27-28`)
- **Detail**: `valid_from => today()->startOfWeek()`, `valid_to => today()->endOfWeek()`. The docblock at `:119-120` says anchoring to the week avoids data that "would silently expire" — it does not; it converts a one-time expiry into a weekly one. The live DB currently holds `2026-07-20 → 2026-07-26`. Today is Saturday 2026-07-25, so on Monday 2026-07-27 the fixture is out of window and the guest homepage (FR-001) will show "brak danych" until someone manually re-runs the seeder. Nothing in the repo — no scheduler entry, no CI step — does that. The integrity test `seeded_leaflets_are_valid_today` re-seeds inside `RefreshDatabase`, so it never catches it either.
- **Fix A ⭐ Recommended**: Widen the seeded window, e.g. `valid_from => today()->startOfWeek()->subWeek()`, `valid_to => today()->endOfWeek()->addWeeks(4)`.
  - Strength: One-line change; a demo fixture has no reason to be calendar-fragile, and S-01 development spans more than the current week.
  - Tradeoff: The fixture no longer models a realistic one-week leaflet cadence, which is what F-03's real ingestion will produce.
  - Confidence: HIGH — the expiry date is directly observable in the seeded DB.
  - Blind spot: If S-01 hardcodes an expectation of a 7-day window anywhere, widening would need a matching test update.
- **Fix B**: Keep the weekly window and register the re-seed (scheduler entry or a documented `ddev artisan db:seed --class=…ExampleBasketSeeder` step).
  - Strength: Fixture stays a faithful model of a weekly leaflet, which is what the production ingestion will actually write.
  - Tradeoff: Adds operational surface for demo data, and a missed run still produces the outage. Interacts with F5 — the standard `db:seed` entry point currently fails on a re-run.
  - Confidence: MEDIUM — correct in principle, but depends on the scheduler being wired, which this change deliberately did not do.
  - Blind spot: Production deploy flow does not currently run seeders at all.
- **Decision**: SKIPPED — expiry is the guardrail behaving correctly, and it was observed doing exactly that on 2026-08-29. Demo data is re-seeded manually with `migrate:fresh --seed`. Revisit if the homepage is ever demoed unattended.

### F3 — Factories build cross-network price entries by default

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `database/factories/PriceEntryFactory.php:27-28` (with `LeafletFactory.php:25`, `NetworkProductFactory.php:23`)
- **Detail**: `leaflet_id => Leaflet::factory()` and `network_product_id => NetworkProduct::factory()` each spawn their own `Network::factory()`. Verified:

  ```
  PriceEntry::factory()->onePlusOne()->create()
    → leaflet.network_id = 3, networkProduct.network_id = 4
  ```

  A Lidl price sitting inside a Biedronka leaflet — impossible in production and unconstrained by the schema (no composite FK can express it). The factory docblock claims a test "cannot accidentally build a row that violates the contract"; it cannot violate the *promo-parameter* contract, but it violates the network contract on every default `create()`. The four mandatory promo-mechanic tests required by `CLAUDE.md` will be built on exactly these rows, and a basket-total calculator that groups by network will read them as incoherent data.
- **Fix**: Have `PriceEntryFactory::definition()` resolve one `Network` and pass it to both child factories — e.g. build the network first, then `Leaflet::factory()->for($network)` and `NetworkProduct::factory()->for($network)`.
  - Strength: Makes the default factory row a shape that can actually exist in production, before S-01 writes four tests on top of it.
  - Tradeoff: Slightly more factory machinery; a test that genuinely wants two networks must be explicit — which is the right default.
  - Confidence: HIGH — reproduced directly; the mismatched `network_id`s are observable.
  - Blind spot: No existing test asserts the mismatch either way, so the fix is currently unguarded by a regression test.
- **Decision**: FIXED in `fbef5d7` — the listing now inherits the leaflet's network; regression test in `tests/Feature/Database/PriceEntryFactoryTest.php`.

### F4 — Promo-parameter contract enforces null-ness but not the plan's pinned values

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: `app/Enums/PromoType.php:47-64`; test consumption at `tests/Feature/Database/PricePromoSeedTest.php:117-133`
- **Detail**: The plan's matrix (`plan.md:91-97`) pinned values, not just presence: `OnePlusOne` → `required_quantity = 2` and `second_item_price = 0.00`; `SecondForFixed` → `required_quantity = 2` and `second_item_price > 0.00`. `requiredParameters()` / `forbiddenParameters()` return column *names* only, so the enum encodes null vs not-null and nothing more.

  Consequence: a `one_plus_one` row with `second_item_price = '5.00'` — i.e. the second item is not free — passes `test_every_price_entry_matches_its_promo_type_parameter_contract` and every other test in the suite. The single place the test checks a value (`:143`, `required_quantity >= 2`) hardcodes the `2` rather than reading it from the enum, which is the one spot where the otherwise-genuine invariant is duplicated.

  Credit where due: the contract *is* a real invariant elsewhere — the test iterates `$type->requiredParameters()` / `->forbiddenParameters()` off the enum instance at `:120` and `:127` rather than restating the matrix. This finding is about the values the plan called load-bearing, not about the mechanism.
- **Fix**: Extend `PromoType` with a value predicate (e.g. `validatesParameterValues(PriceEntry|array $row): bool`, or a `parameterValueRules(): array` returning `second_item_price` as `= 0.00` / `> 0.00` and `required_quantity` as `>= 2`) and consume it inside the loop at `PricePromoSeedTest.php:117`, replacing the hardcoded `2` at `:143`.
  - Strength: Closes the "1+1 where the second item costs money" hole before the rule engine branches on these rows; keeps the matrix in one place, as the plan intended.
  - Tradeoff: The value rules are inequalities, not equalities, so the API is a touch more involved than the current name-list return.
  - Confidence: HIGH — the gap was confirmed by reading the enum's `match` arms; they carry no values.
  - Blind spot: Whether the rule engine will want these as validation or as calculation inputs — the shape may want revisiting in S-01.
- **Decision**: DEFERRED to F-03. Value-level validation guards against mis-parsed rows, and every row today is hand-written in the seeder. It becomes load-bearing when the vision pipeline writes entries.

### F5 — `db:seed` through the standard entry point fails on a second run

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `database/seeders/DatabaseSeeder.php:20`
- **Detail**: Verified — `ddev artisan db:seed --force` against an already-seeded database throws a unique-constraint violation on `users.email` at `DatabaseSeeder.php:20`, *before* reaching the `$this->call(ExampleBasketSeeder::class)` added at `:25`. ExampleBasketSeeder's carefully built idempotency is therefore unreachable through the default entry point; only the explicit `--class=…\ExampleBasketSeeder` invocation works, which is what Progress item 2.3 tests. The plan required keeping the Test User creation "intact" — it was kept intact, and the pre-existing non-idempotency came with it, but the plan also required a seeder that survives re-running during S-01 development.
- **Fix**: `User::firstOrCreate(['email' => 'test@example.com'], ['name' => 'Test User'])` in place of `User::factory()->create([...])`.
- **Decision**: FIXED in `fbef5d7` — Test User creation guarded by an existence check.

### F6 — Seeder is idempotent for additions, not for edits

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `database/seeders/ExampleBasketSeeder.php:84-89`
- **Detail**: Price entries are keyed on `(leaflet_id, network_product_id, promo_type)` — matching the unique index, which is correct for re-running. But changing a catalogue entry's `promo_type` and re-seeding leaves the old `PriceEntry` behind, so that listing ends up with two competing entries and a future comparison may double-count. Editing prices in place is fine; editing mechanics is not. This will be exercised during S-01 development, which is precisely when the seeder gets re-run.
- **Fix**: Delete the seed's price entries for the fixture leaflets before re-inserting, or key deletion on `LEAFLET_SOURCE_REFERENCE`.
- **Decision**: SKIPPED — `migrate:fresh --seed` is the standard move after editing the catalogue, so the edit case does not arise in practice.

### F7 — `endOfWeek()` writes `23:59:59`, and the two drivers store it differently

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `database/seeders/ExampleBasketSeeder.php:138`, `database/factories/LeafletFactory.php:28`
- **Detail**: `today()->endOfWeek()` carries a `23:59:59` time component, and Eloquent's `date` cast serializes on write via `fromDateTime()` → `'Y-m-d H:i:s'`. MySQL truncates to `2026-07-26`; SQLite stores the literal `'2026-07-26 23:59:59'`. Both `validOn()` scopes survive it (`date()`/`strftime()` normalize) and so does `Carbon::parse(Leaflet::max('valid_to'))` in the test — but any future raw string comparison or `max()` on `valid_to` behaves differently per driver, in a codebase that deliberately runs MySQL in prod and SQLite in tests.
- **Fix**: `->endOfWeek()->startOfDay()` (or `->toDateString()`) at both write sites.
- **Decision**: SKIPPED, together with F2 — the seeded window is unchanged, so the `23:59:59` component stays. One-line fix if a raw `valid_to` comparison ever appears.

### F8 — App timezone is UTC for a Poland-only product

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `config/app.php:68` (pre-existing, not in this diff — flagged because this change makes it load-bearing)
- **Detail**: `today()` resolves in UTC. Leaflet validity therefore flips at 02:00 Europe/Warsaw rather than at midnight, giving a two-hour window each night where the data-freshness verdict disagrees with the user's calendar. Harmless today; it becomes a "verdict lies about freshness" edge once F-03 writes real dated leaflets and the nightly refresh (FR-009) runs.
- **Fix**: Set `'timezone' => 'Europe/Warsaw'` in `config/app.php`, or decide explicitly to keep UTC and normalize at the boundary.
- **Decision**: FIXED in `fbef5d7` — `config/app.php` timezone set to `Europe/Warsaw`.

### F9 — Planned `network_product_id` index skipped, and the migration imports the enum

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `database/migrations/2026_07_25_120005_create_price_entries_table.php:47-48` (and `:3`, `:36`)
- **Detail**: Two small deviations in the same migration, neither harmful.

  (a) The plan specified an explicit index on `price_entries.network_product_id`; the implementation skips it with a comment stating InnoDB auto-creates one for the foreign key. Verified true — `SHOW INDEX FROM price_entries` returns `price_entries_network_product_id_foreign`. Correct call for MySQL; note SQLite (the test connection) does not auto-create FK indexes, which is irrelevant at fixture scale.

  (b) The migration imports `App\Enums\PromoType` (`:3`) to supply the `promo_type` column default (`:36`). Standard Laravel caution: renaming or removing a case later breaks `migrate` on a fresh database, because migrations are meant to be frozen history while enums evolve.
- **Fix**: Leave (a) as implemented and note the deviation in the plan; for (b), inline the literal `'none'` with a comment pointing at `PromoType`.
- **Decision**: (a) DECLINED — InnoDB creates the foreign-key index and the migration says so; the deviation is deliberate. (b) FIXED in `fbef5d7` — literal `'none'` with a comment, enum import dropped.

### F10 — N+1 query patterns inside the integrity test

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/Database/PricePromoSeedTest.php:72, 89-90, 194`
- **Detail**: `NetworkProduct::where(...)->get()` per product and `$listing->priceEntries()->count()` per listing — a query per loop iteration. Harmless at fixture scale (the suite runs in 0.86s), but it is the exact pattern `CLAUDE.md` bans for the comparison path, and test code is where habits get copied into S-01.
- **Fix**: `Product::with('networkProducts')` and `->withCount('priceEntries')` in place of the per-iteration queries.
- **Decision**: SKIPPED — cosmetic, in test code, at fixture scale. The ban on N+1 is enforced where it matters: `BasketComparatorEdgeCasesTest` asserts a bounded query count on the comparison path.

## Clean findings (no action)

- **Security** — clean. No HTTP surface, no authz boundary, no secrets. `DB::raw|whereRaw|selectRaw|statement(` returns nothing across `app/`, `database/`, `config/`; every write goes through Eloquent with bound parameters.
- **Data safety** — clean. All five migrations have a matching `down()` with `dropIfExists`; reverse-chronological rollback respects the FK graph. No `$table->enum()`, no generated columns, no raw DDL — the MySQL/SQLite portability constraint from `plan.md:22` is honoured.
- **Money model** — clean and correctly documented. Casts confirmed to return strings (`regular_price` → `'9.99'` *(string)*). No arithmetic or `<`/`==` comparison on any cast price value exists anywhere in the diff. The BCMath/integer-grosze contract is stated in both places the plan required: `app/Enums/PromoType.php:13-18` and `app/Models/PriceEntry.php:18-23`.
- **Scope discipline** — clean. No `baskets`/`basket_items` tables, no `user_id` ownership, no rule engine, no ingestion/jobs/vision, no Blade/controllers, `routes/web.php` untouched, no auth, no matching automation, no admin CRUD. In-scope extras beyond the plan — `PromoType::isConditional()` / `::parameterColumns()`, the seeder's `RuntimeException` guard on unknown config slugs, `LEAFLET_SOURCE_REFERENCE`, and four unplanned tests — are all beneficial; two of them (`one_listing_carries_both_a_regular_and_a_loyalty_card_price`, `the_seeder_is_idempotent`) directly harden plan requirements.
- **Pattern consistency** — clean. Models mirror `app/Models/User.php` (`#[Fillable([...])]` attribute, `casts(): array` method, `/** @use HasFactory<XFactory> */`). Migrations use `return new class extends Migration`. Factories match `UserFactory`. The test is PHPUnit, namespaced `Tests\Feature\Database`, extends `Tests\TestCase`, uses `RefreshDatabase` — consistent with `tests/Feature/ExampleTest.php`. `#[Scope]` usage is correct (protected, unprefixed, `Builder` first param) and works both statically and nested inside `whereHas`.
