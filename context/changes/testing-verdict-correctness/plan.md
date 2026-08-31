# Test Rollout Phase 1 — Verdict Correctness on Real Leaflet Shapes

## Overview

Make Risks #1 and #6 from `context/foundation/test-plan.md` assertable, without fixing the production defect research uncovered.

Research proved that `PromoCalculator::conditional()` is correct only at `required_quantity = 2`, while real Lidl leaflets print N=3 for both `one_plus_one` ("2+1 gratis") and `second_for_fixed` ("Trzeci, najtańszy za grosz") — and that the mispricing flips the verdict to the wrong chain. This phase writes the till-total oracle for every mechanic across the thresholds real leaflets actually print, lands the N≠2 cases as deliberately-failing tests in an excluded group, and closes the fixture-integrity holes that let a green suite prove nothing.

## Current State Analysis

**Pricing coverage (22 methods in `tests/Feature/Pricing/`, 19 in `tests/Feature/Database/`):**

- All six methods in `BasketComparatorTest.php` seed **one network** (`:155`), so the five mandatory mechanic tests assert `resultFor('lidl')->total` and **cannot produce a verdict**. `CLAUDE.md`'s mandate ("each must have a PHPUnit test asserting the computed basket total") is satisfied literally while the failure Risk #1 names — a wrong *verdict* — is uncovered.
- `one_plus_one` and `second_for_fixed` are tested at N=2 only; `conditional_unit_price` at N=3 only; `loyalty_card` at quantity 1 only.
- Expected values are hard-coded literals with hand-derived comments — the mirror/oracle anti-pattern is **already avoided** and is not this phase's problem.
- No test asserts that a quantity-driven mechanic decides which chain wins.

**Fixture integrity:**

- `PriceEntryFactory::definition()` derives `network_product_id` from the leaflet's chain, and `PriceEntryFactoryTest` asserts it — but only for `create()` and four of five states. `conditionalUnitPrice()` is absent from the states list (`PriceEntryFactoryTest.php:33-38`).
- The derivation is one-directional: `->for($listing, 'networkProduct')` overwrites it and leaves `leaflet_id => Leaflet::factory()`, spawning a foreign chain. Measured live: `leaflet.network_id=9`, `listing.network_id=8`. Two call sites already do this (`PriceEntryGateTest.php:120-126`, `:138-144`).
- `onePlusOne()` and `secondForFixed()` hard-code `required_quantity => 2` with no parameter (`PriceEntryFactory.php:63`, `:76`), so the factory **cannot build the real N=3 shape** at all.
- SQLite accepts rows MySQL 8.0 rejects (measured: `required_quantity = 300`, `regular_price = 1000000.00`), so a fixture can encode a row production cannot hold and the suite stays green.

**Suite conventions:** `test_snake_case` methods, `RefreshDatabase`, **zero PHPUnit attributes anywhere**. `composer test` runs `artisan test` across both suites and is the pre-push gate (§5).

## Desired End State

- Every mechanic has a till-total assertion at each threshold real leaflets print, at boundary quantities, with the expected value derived from leaflet text and the PRD — never from the engine's arithmetic.
- The N=3 mispricing is measured, not merely described: running `composer test:all` prints the exact engine-vs-till diff for each failing case, and those cases turn green by themselves when the defect is fixed.
- `composer test` stays green, so it remains a usable pre-push gate for every other regression.
- A verdict test proves that on a deliberate near-tie, the mechanic decides which chain wins — over a two-chain fixture.
- No factory path can produce a cross-chain row; every factory state's default row is proven insertable into MySQL 8.0.
- §6.1 and §6.2 of the test plan carry the patterns, and `lessons.md` carries the two corrections.

### Key Discoveries

- `PromoCalculator` is pure over an unsaved `PriceEntry` — the whole mechanic matrix needs **no database** (`app/Pricing/PromoCalculator.php:23-37`). This is cheaper than the integration layer the test plan originally named.
- Groups apply per **method**, not per provider case — so a provider mixing N=2 (passing) and N=3 (failing) cases cannot be tagged selectively. Verified: the matrix must split into an ungrouped method and a `#[Group('known-defect')]` method per affected mechanic.
- A shell env var **overrides** `phpunit.xml`'s `<env>` block (verified: `Connection: mysql, Database: koszykomat_test`). The MySQL lane therefore needs **no `phpunit.xml` change** — env vars plus `--group=mysql` are sufficient.
- `--exclude-group` must be **repeated per group**, not comma-separated. `--exclude-group=a,b` parses without error and silently filters *nothing*; `--exclude-group=a --exclude-group=b` filters correctly. Corrected during Phase 2 after the comma form let all six known-defect cases into the gate — the planning-time check had run the comma form when no test carried either group, so "passed" proved only that the flag parses.
- The forced-overbuy figure is a **disclosure**, not a total component (`PromoCalculator.php:12-15`; PRD §Business Logic). A test that adds overbuy units into an expected total asserts the opposite of the PRD.

## What We're NOT Doing

- **Not fixing `PromoCalculator::conditional()`.** The fix needs a schema/semantics decision (add a `discounted_quantity` column vs redefine `required_quantity`), which belongs to its own change. `CLAUDE.md` §Lesson boundaries reserves the bug → fix → regression-test workflow for Lesson 5.
- **Not asserting rendered report content** — validity windows, the overbuy figure as the user reads it, and matched-pair disclosure are Phase 2 (Risk #2).
- **Not changing the single-network winner behaviour.** `BasketComparator::decide():242-244` returns a winner with a zero margin when only one chain exists. Production always holds two, so this phase forbids one-chain fixtures in verdict tests rather than changing the production rule.
- **Not addressing the tie-break that discards `promoRequiredMoreItems`** (`BasketComparator.php:150-156`). Recorded in research; it is a disclosure concern and belongs with Phase 2.
- **Not moving the suite to MySQL.** Only the `mysql` group runs there.
- **Not testing ingestion parsers.** Phase 5 (Risk #4) owns that.

## Implementation Approach

Two defects surfaced in research, and they are treated differently:

| Defect | Kind | Treatment here |
|---|---|---|
| `conditional()` mispricing at N≠2 | **Production** logic | Tests only, landing red in the `known-defect` group |
| `PriceEntryFactory` `->for()` escape hatch | **Test infrastructure** | Fixed in this phase, then asserted green |

That line is what keeps "tests only" honest: a test-infrastructure defect is squarely this phase's job, while a production defect is documented and handed on.

The mechanic matrix runs as unit tests (no DB, milliseconds), the verdict runs as integration tests (two chains, real DB), fixture coherence runs on SQLite, and fixture range/type validity runs against MySQL 8.0 in an opt-in group.

## Critical Implementation Details

**The group split is load-bearing and non-obvious.** PHPUnit applies `#[Group]` to a method, so each affected mechanic needs two methods sharing one assertion helper: `test_<mechanic>_till_total` (thresholds the engine gets right) and `test_<mechanic>_till_total_at_real_leaflet_thresholds` (`#[Group('known-defect')]`). Tagging one method with both providers' cases would exclude the passing cases from the gate and silently lose protection.

**Ordering within Phase 1.** The `afterCreating` coherence guard must land *together with* the `PriceEntryGateTest` migration in the same step — the guard makes those two existing tests throw the moment it exists.

**Do not derive expected totals from `required_quantity`.** The till rule for the "X+Y gratis" family is *`N − free` items at the shelf price plus `free` items at the second-item price*, where `free` comes from the leaflet's wording ("2+1" → 1 free of 3). Writing the expectation as `regular + second × (N−1)` reproduces the bug under test.

## Phase 1: Test infrastructure — thresholds, coherence guard, group wiring

### Overview

Make the real-leaflet shapes buildable, make an incoherent row impossible by any factory path, and create the two lanes (`known-defect`, `mysql`) that later phases rely on. No new assertions about pricing yet.

### Changes Required:

#### 1. Factory threshold parameters

**File**: `database/factories/PriceEntryFactory.php`

**Intent**: The factory cannot express the shapes real leaflets print, because two states hard-code their threshold. Parameterise them so a test can build "2+1 gratis" and "Trzeci … za grosz" without hand-writing raw attribute arrays.

**Contract**: `onePlusOne(int $requiredQuantity = 2)` and `secondForFixed(string $secondItemPrice = '1.00', int $requiredQuantity = 2)`. Defaults are unchanged, so no existing test moves. `conditionalUnitPrice()` already takes both parameters and needs no change.

#### 2. Cross-chain coherence guard

**File**: `database/factories/PriceEntryFactory.php`

**Intent**: The current derivation protects the default path but is silently bypassed by `->for(..., 'networkProduct')`, which is exactly the shape `lessons.md` forbids. Make the factory refuse to produce an incoherent row through any path, so misuse fails loudly at the point of creation instead of producing a fixture that quietly proves nothing.

**Contract**: A `configure()` hook that, after creating, throws `LogicException` when `leaflet->network_id !== networkProduct->network_id`, naming both chains in the message. Plus a `forListing(NetworkProduct $listing)` state that pins `network_product_id` to that listing **and** builds the leaflet in the same chain — the supported way to attach a price to an existing listing.

#### 3. Migrate the two incoherent call sites

**File**: `tests/Feature/Ingestion/PriceEntryGateTest.php`

**Intent**: Both `->for($listing, 'networkProduct')` call sites produce cross-chain rows today and will throw once the guard exists. Switch them to `forListing()`. Research confirmed the gate queries only by `network_product_id`, so no assertion changes.

**Contract**: Lines `:120-126` and `:138-144`; assertions and expected gate verdicts unchanged.

#### 4. Test lanes

**File**: `composer.json`

**Intent**: Keep `composer test` a usable pre-push gate while the known defect stands, and give the MySQL lane an explicit, idempotent entry point. Verified that no `phpunit.xml` change is required — a shell env var overrides its `<env>` block. Note the `--exclude-group` repetition rule in Key Discoveries: a comma list silently filters nothing.

**Contract**: four scripts —

- `test` → config clear, then `artisan test --exclude-group=known-defect --exclude-group=mysql` (the gate)
- `test:all` → `artisan test` (everything; expected red while the defect stands)
- `test:mysql` → `DB_CONNECTION=mysql DB_DATABASE=koszykomat_test artisan test --group=mysql`
- `test:mysql:setup` → create the `koszykomat_test` schema, grant the `db` user, migrate; idempotent, run once per environment

### Success Criteria:

#### Automated Verification:

- `composer lint` passes
- `composer test` passes with the same test count as before, minus none
- `PriceEntryGateTest` passes with the migrated call sites
- The coherence guard throws when a cross-chain row is built directly (proven by a temporary scratch assertion, removed before commit)
- `composer test:mysql:setup` completes and is safe to re-run

#### Manual Verification:

- `composer test:all` runs without configuration errors (failures from later phases are expected once they land)
- The `LogicException` message names both chains clearly enough to diagnose from CI output alone

---

## Phase 2: Till-total matrix (unit)

### Overview

Assert what a shopper actually pays, per mechanic, per threshold, at boundary quantities — with no database.

**Behaviour asserted**: for one price entry and a requested quantity, `PromoCalculator::cost()` returns the amount the till would charge, with the shopper charged for exactly the quantity requested.
**Regression caught**: any change to a mechanic's formula that alters a charged amount, at any threshold — not just the seeded one.
**Research source**: `research.md` §A.2-A.3 (real Lidl tiles and the measured engine-vs-till table), PRD §Business Logic (the remainder rule), `lidl-tiles.txt:16-34`.
**Edge / error / boundary cases**: quantity 0 and negative (returns `null`); `promo_price = null` on `conditional_unit_price` — the state *every* real Lidl conditional row is in today; `required_quantity = null`; `second_item_price = null`; a promo price above the regular price (the clamp).
**Anti-pattern avoided**: no expected value is computed with the engine's own formula; no mechanic is represented by a single happy path; no single threshold stands in for all thresholds.

### Changes Required:

#### 1. The matrix

**File**: `tests/Unit/Pricing/PromoCalculatorTest.php` (new)

**Intent**: One assertion helper, and per mechanic a pair of provider-driven methods — one for thresholds the engine prices correctly, one tagged `#[Group('known-defect')]` for the real-leaflet thresholds it does not. Every expected value is a literal traceable to a leaflet phrase or a PRD sentence in the case name or a comment.

**Contract**: cases below. Quantities follow the boundary set {1, N−1, N, N+1, 2N}. The `known-defect` rows are the measured engine-vs-till divergences from research §A.3.

| Mechanic | Shape (source) | Lane |
|---|---|---|
| `none` | regular 9.99 | green |
| `simple` | regular 5.99 → 4.59 (`vision.md:83`, Lidl olej) | green |
| `simple` | promo above regular → clamp, `appliedPromo = None` | green |
| `loyalty_card` | regular 8.49 → 6.49, **quantities 1 and 3** (today only 1 is tested) | green |
| `loyalty_card` | card price above regular → clamp | green |
| `one_plus_one` | N=2, regular 3.49 (seed) | green |
| `one_plus_one` | **N=3, regular 4.49** — "2+1 gratis" (`lidl-tiles.txt:24-28`) | **known-defect** |
| `second_for_fixed` | N=2, regular 4.99, second 0.01 (seed) | green |
| `second_for_fixed` | N=2, regular 10.99, second **1.00** — "za złotówkę" | green |
| `second_for_fixed` | **N=3, regular 89.99, second 0.01** — "Trzeci, najtańszy za grosz" (`lidl-tiles.txt:30-34`) | **known-defect** |
| `conditional_unit_price` | N=2, regular 17.99 (`vision.md:104`) | green |
| `conditional_unit_price` | N=3, regular 6.00 → 4.00 (existing shape) | green |
| `conditional_unit_price` | **N=6, regular 3.30** — Mleko Łączka (`lidl-tiles.txt:16-22`); promo synthetic, see note | green |

**Note on the N=6 promo price**: Lidl never labels the conditional unit price, so every real row is `promo_price = null`. The N=6 *threshold* is real; the promo price is synthetic and must be commented as such, with a companion case asserting that `promo_price = null` yields `null` — the shape production is actually in.

#### 2. Overbuy disclosure at the calculator

**File**: `tests/Unit/Pricing/PromoCalculatorTest.php`

**Intent**: Pin `promoRequiredMoreItems` as a flag about the promo not firing — and, critically, that it is **not** raised merely because a remainder exists.

**Contract**: `conditional_unit_price` N=3 at quantity 2 → `true`, at 3 → `false`; `one_plus_one` N=2 at quantity 1 → `true`; **N=6 at quantity 7 → `false`** (one full group fired; the shopper is not short). No test adds overbuy units into an expected total.

### Success Criteria:

#### Automated Verification:

- `composer test` passes — every green-lane case
- `composer test:all` fails **only** in `known-defect`, and the failures are exactly the cases research measured
- Each failing case reports independently, naming its threshold and quantity
- `composer lint` passes

#### Manual Verification:

- Each expected value traces to a leaflet phrase or a PRD sentence — spot-check three
- A failing case's diff reads as "engine charged X, till charges Y" without opening the source

---

## Phase 3: Verdict on deliberate near-ties (integration)

### Overview

Prove the mechanic decides which chain wins.

**Behaviour asserted**: over a two-chain basket engineered so the mechanic is the only thing separating the totals, the verdict names the chain the till agrees with.
**Regression caught**: a mechanic mispriced badly enough to hand the win to the wrong chain — the exact Risk #1 failure, which no existing test can produce.
**Research source**: `research.md` §A.4 (the measured end-to-end flip), §A.5 (why the one-chain helper hides it).
**Edge / boundary cases**: the threshold as the pivot — the same pair of chains flips winner as the quantity crosses N; a tie asserted as `VerdictType::Tie`, not as a winner with a zero margin.
**Anti-pattern avoided**: no verdict is asserted over a one-chain fixture; margins are asserted as values rather than inferred from ordering.

### Changes Required:

#### 1. Two-chain verdict cases

**File**: `tests/Feature/Pricing/BasketComparatorVerdictTest.php` (new)

**Intent**: A helper that always seeds **both** chains — the shape production holds — and cases where the mechanic is the sole difference.

**Contract**:

- **Threshold pivot (green)**: Lidl `conditional_unit_price` N=6, regular 3.30, promo 1.99 vs Biedronka `simple` at 2.00. At quantity 6, Lidl wins (11.94 vs 12.00, margin 0.06). At quantity 5, Biedronka wins (16.50 vs 10.00). Same fixture, opposite verdicts — the threshold is what moves it.
- **Exact tie (green)**: both chains land on the same total; assert `VerdictType::Tie` and a null winner.
- **The real flip (`#[Group('known-defect')]`)**: Lidl "2+1 gratis" regular 4.49 vs Biedronka `simple` 2.80 at quantity 3. Till: Biedronka 8.40 beats Lidl 8.98. Engine today names Lidl. Assert the till-truthful verdict.

#### 2. Two-chain fixture rule

**File**: `tests/Feature/Pricing/BasketComparatorVerdictTest.php`

**Intent**: Make the one-chain trap unrepeatable in this file rather than relying on discipline.

**Contract**: the helper asserts `Network::count() >= 2` before comparing, so a future case that forgets the second chain fails on the fixture rather than producing a hollow winner.

### Success Criteria:

#### Automated Verification:

- `composer test` passes — pivot and tie cases green
- `composer test:all` shows the real-flip case failing with `winner = lidl` against an expected `biedronka`
- `composer lint` passes

#### Manual Verification:

- The pivot case's two quantities genuinely flip the winner (confirm by reading the asserted totals, not just the verdict)

---

## Phase 4: Fixture integrity — coherence and production-holdable shapes

### Overview

Prove a factory row is a shape production can hold, on both axes.

**Behaviour asserted**: every factory path yields a row whose leaflet and listing share a chain, and whose values fit the production column types.
**Regression caught**: a factory change that reintroduces the cross-chain row (`lessons.md`), and a fixture value that SQLite stores but MySQL 8.0 rejects.
**Research source**: `research.md` §C.1 (the measured `->for()` cross-chain row), §B.2 (the measured SQLite/MySQL constraint table).
**Edge / boundary cases**: `required_quantity = 300` against `unsignedTinyInteger`; `regular_price = 1000000.00` against `decimal(8,2)`; every one of the five states, `conditionalUnitPrice()` included.
**Anti-pattern avoided**: assertions compare the two chains to *each other* rather than re-deriving the factory's own derivation logic; coverage is not limited to the default `create()` path.

### Changes Required:

#### 1. Coherence across every path

**File**: `tests/Feature/Database/PriceEntryFactoryTest.php`

**Intent**: Extend the existing class from four states to every path a caller can take.

**Contract**: coherence asserted for `create()`, all **five** states (adding `conditionalUnitPrice()`, absent today), the parameterised thresholds from Phase 1, and `forListing()`. Plus one assertion that the guard **has teeth**: building a deliberately cross-chain row raises `LogicException`. Without that, a green coherence test would prove only that nobody tried.

#### 2. Production-holdable shapes

**File**: `tests/Feature/Database/FixtureShapeMySqlTest.php` (new, `#[Group('mysql')]`)

**Intent**: SQLite cannot see a row MySQL rejects, so the only honest layer is MySQL itself. Assert both directions — the factory's rows are accepted, and rows production would reject are rejected.

**Contract**: against `koszykomat_test` on MySQL 8.0 — every factory state's default row inserts successfully; `required_quantity = 300` and `regular_price = 1000000.00` are both rejected with `QueryException`. The rejection cases are what prove the lane is real rather than vacuously green.

### Success Criteria:

#### Automated Verification:

- `composer test` passes (coherence tests; `mysql` group excluded)
- `composer test:mysql` passes — acceptance and rejection cases both
- The `mysql` group does **not** run in the default `composer test` (confirm by test count)
- `composer lint` passes

#### Manual Verification:

- `composer test:mysql` fails cleanly with a clear message when the schema is missing, pointing at `composer test:mysql:setup`

---

## Phase 5: Cookbook and lessons

### Overview

Record the patterns so the next contributor reaches for them, and correct the two inaccuracies research found.

### Changes Required:

#### 1. Cookbook §6.1 — promo-mechanic pricing tests

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the TBD with the till-total pattern actually shipped.

**Contract**: the unit-first rule (the calculator needs no DB); expected values derive from leaflet text or PRD, never from the engine's formula; every mechanic covered at each threshold real leaflets print, at boundary quantities {1, N−1, N, N+1, 2N}; the `known-defect` group and why it exists; overbuy is disclosed, never added to a total; a verdict is asserted only over a two-chain fixture.

#### 2. Cookbook §6.2 — factories

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the TBD with the fixture-integrity pattern.

**Contract**: never use `->for(..., 'networkProduct')` on `PriceEntryFactory` — use `forListing()`; a new state must be added to the coherence test's state list; the factory guard exists and a `LogicException` from it means the fixture is wrong, not the guard; range/type validity is asserted in the `mysql` group only.

#### 3. Gate note in §5

**File**: `context/foundation/test-plan.md`

**Intent**: The pre-push gate now runs a filtered suite; a reader must not mistake green for complete.

**Contract**: record that `composer test` excludes `known-defect` and `mysql`, that `composer test:all` is expected red until the `conditional()` defect is fixed, and that `composer test:mysql` is the ad-hoc integration gate §5 already anticipated.

#### 4. Lessons corrections

**File**: `context/foundation/lessons.md`

**Intent**: The existing entry is right in spirit and wrong in two specifics, both of which cost time to rediscover.

**Contract**: amend the factory entry with the `->for()` escape hatch (deriving one parent from another protects the default path only; a relationship override silently re-opens it) and correct "no composite foreign key can forbid" — one *is* expressible by denormalising `network_id` and adding `unique(id, network_id)` to both parents; it is a schema-cost decision, not an impossibility.

### Success Criteria:

#### Automated Verification:

- `composer lint` passes
- `composer test` passes
- No `TBD` remains in §6.1 or §6.2

#### Manual Verification:

- A reader who has not seen this change could add a sixth mechanic's tests from §6.1 alone
- The §5 note makes clear that a green `composer test` does not mean the defect is gone

---

## Testing Strategy

### Unit Tests

- The full mechanic × threshold × quantity matrix, provider-driven, no database (Phase 2)
- Error branches: quantity < 1, null promo parameters, promo above regular
- `promoRequiredMoreItems` semantics, including the remainder case that must **not** raise it

### Integration Tests

- Two-chain verdict on deliberate near-ties, with the threshold as the pivot (Phase 3)
- Factory coherence across every construction path, plus the guard's own teeth (Phase 4)
- Production-holdable shapes against MySQL 8.0, acceptance and rejection (Phase 4)

### Manual Testing Steps

1. Run `composer test` — green, and the count excludes the `known-defect` and `mysql` groups.
2. Run `composer test:all` — red **only** in `known-defect`; each failure names its threshold and quantity.
3. Run `composer test:mysql` — green.
4. Read three failing cases and confirm the expected value traces to a leaflet phrase or PRD sentence.

## Migration Notes

`composer test:mysql:setup` must be run once per environment before the `mysql` group can run. It creates `koszykomat_test`, grants the `db` user, and migrates — all idempotent. Already verified working on this machine, including the grant, which is the step that fails first without it.

## References

- Research: `context/changes/testing-verdict-correctness/research.md`
- Test plan: `context/foundation/test-plan.md` §2 (Risks #1, #6), §4, §5, §6, §7
- Lessons: `context/foundation/lessons.md`
- Real leaflet fixtures: `tests/Fixtures/Ingestion/lidl-tiles.txt:16-34`
- The defect: `app/Pricing/PromoCalculator.php:71-97`
- The factory: `database/factories/PriceEntryFactory.php:24-40`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Test infrastructure — thresholds, coherence guard, group wiring

#### Automated

- [x] 1.1 `composer lint` passes — 70a0339
- [x] 1.2 `composer test` passes with no lost tests — 70a0339
- [x] 1.3 `PriceEntryGateTest` passes with migrated call sites — 70a0339
- [x] 1.4 Coherence guard throws on a cross-chain row — 70a0339
- [x] 1.5 `composer test:mysql:setup` completes and is re-runnable — 70a0339

#### Manual

- [x] 1.6 `composer test:all` runs without configuration errors — 70a0339
- [x] 1.7 `LogicException` message names both chains — 70a0339

### Phase 2: Till-total matrix (unit)

#### Automated

- [x] 2.1 `composer test` passes — every green-lane case
- [x] 2.2 `composer test:all` fails only in `known-defect`, matching research §A.3
- [x] 2.3 Each failing case reports independently with threshold and quantity
- [x] 2.4 `composer lint` passes

#### Manual

- [x] 2.5 Three expected values spot-checked against leaflet or PRD text
- [x] 2.6 A failing diff reads as engine-vs-till without opening source

### Phase 3: Verdict on deliberate near-ties (integration)

#### Automated

- [ ] 3.1 `composer test` passes — pivot and tie cases
- [ ] 3.2 `composer test:all` shows the real-flip case failing as measured
- [ ] 3.3 `composer lint` passes

#### Manual

- [ ] 3.4 The pivot case's two quantities genuinely flip the winner

### Phase 4: Fixture integrity — coherence and production-holdable shapes

#### Automated

- [ ] 4.1 `composer test` passes — coherence across all five states and `forListing()`
- [ ] 4.2 `composer test:mysql` passes — acceptance and rejection
- [ ] 4.3 The `mysql` group does not run in the default `composer test`
- [ ] 4.4 `composer lint` passes

#### Manual

- [ ] 4.5 `composer test:mysql` fails clearly when the schema is missing

### Phase 5: Cookbook and lessons

#### Automated

- [ ] 5.1 `composer lint` passes
- [ ] 5.2 `composer test` passes
- [ ] 5.3 No `TBD` remains in §6.1 or §6.2

#### Manual

- [ ] 5.4 §6.1 alone is enough to add a sixth mechanic's tests
- [ ] 5.5 §5 note makes clear that green ≠ defect gone
