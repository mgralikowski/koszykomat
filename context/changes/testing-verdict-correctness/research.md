---
date: 2026-08-30T22:46:59+02:00
researcher: Marek Gralikowski
git_commit: 259b66112ba0032971144e9eaa92b262655da45d
branch: main
repository: koszykomat
topic: "Rollout Phase 1 — verdict correctness on real leaflet shapes (Risks #1, #6)"
tags: [research, codebase, pricing, promo-mechanics, factories, fixtures, sqlite-mysql]
status: complete
last_updated: 2026-08-30
last_updated_by: Marek Gralikowski
---

# Research: Verdict correctness on real leaflet shapes (Risks #1, #6)

**Date**: 2026-08-30T22:46:59+02:00
**Researcher**: Marek Gralikowski
**Git Commit**: `259b661`
**Branch**: main
**Repository**: koszykomat

## Research Question

Ground rollout Phase 1 of `context/foundation/test-plan.md`:

- **Risk #1** — a promo mechanic is mispriced on a leaflet shape the hand-seed never contained, and the verdict names the wrong chain.
- **Risk #6** — test fixtures encode a shape production cannot hold, so a green suite proves nothing.

Verify (not blindly accept) the plan's response guidance, locate the real failure path in code, establish what the existing ~41 pricing/database test methods actually assert, pick the cheapest useful layer, and flag speculative risks or misleading evidence.

## Summary

Both risks are real, but **neither is where the test plan expected it**, and the single most valuable finding is a live defect rather than a coverage gap.

1. **Risk #1 is realised, today, as a concrete mispricing that flips the verdict.** `PromoCalculator::conditional()` prices `one_plus_one` and `second_for_fixed` with a formula that is correct **only at `required_quantity = 2`**. Every hand-seeded row for those mechanics uses N=2. The real Lidl leaflet produces **N=3** for both of them (`2+1 gratis`, `Trzeci, najtańszy za grosz`), the parser emits N=3, the validation gate passes it, and the engine then undercharges by roughly a full regular price per group. Proven end-to-end: the engine names Lidl the winner on a basket where the till makes Biedronka cheaper.

2. **The risk statement points at the wrong mechanic.** The PRD and the plan treat `conditional_unit_price` as the dangerous one (Lidl's dominant mechanic, 94 hits/leaflet). That mechanic's formula is correct at every N tested. Meanwhile **no real ingested `conditional_unit_price` row can be priced at all** — Lidl never labels the conditional price, so every such row carries `promo_price = null`, is flagged, and surfaces as "brak danych". The reachable defect is in the two mechanics nobody was worried about.

3. **The five mandatory mechanic tests cannot catch a wrong verdict, by construction.** All six methods in `BasketComparatorTest.php` seed **only one network**, so they assert `resultFor('lidl')->total` and never a verdict. The only verdict-flip test in the suite is driven by the loyalty-card scenario switch, not by a quantity-driven mechanic.

4. **Risk #6 is also realised today.** The `lessons.md` rule ("never let related factories each create their own parent") is violated in the live suite: `PriceEntry::factory()->for($listing, 'networkProduct')` overwrites the derivation and re-creates a foreign leaflet. Measured: `leaflet.network_id=9`, `listing.network_id=8`.

5. **The SQLite/MySQL "must challenge" line is half wrong and half under-stated.** The *decimal arithmetic* fear is disproved — there is no SQL money math, `Money` is BCMath on strings, and the `decimal:2` cast normalises SQLite's float back to MySQL's exact string. The *constraint* divergence is real and lands on Risk #6: SQLite silently accepts rows MySQL 8.0 rejects outright. And ddev already runs MySQL 8.0, so the plan's cost objection to a MySQL lane is weaker than §7 assumes.

## Detailed Findings

### A. Risk #1 — the real failure path

#### A.1 The defective formula

`app/Pricing/PromoCalculator.php:71-97` prices `one_plus_one` and `second_for_fixed` together:

```php
$groups = intdiv($quantity, $requiredQuantity);
$remainder = $quantity % $requiredQuantity;

$groupCost = $regular->plus($secondItemPrice->times($requiredQuantity - 1));

$total = $groupCost->times($groups)->plus($regular->times($remainder));
```

The docblock states the model plainly (`PromoCalculator.php:64-69`):

> within a complete group the shopper pays the regular price once plus the second-item price for every further item in the group
> At `required_quantity = 2` that is `regular + 0.00` per pair for 1+1 and `regular + 0.01` for second-for-grosz.

**The author reasoned only about N=2, and the formula is only correct there.** It assumes *one paid item + (N−1) discounted items*. A "2+1 gratis" group is the opposite shape: *two paid items + one free*.

`required_quantity` conflates two different quantities — **how many you must buy** and **how many are discounted**. At N=2 they coincide (1 paid, 1 free), which is why this survived review, the seeder, and all five mandatory tests. There is no column expressing "how many items in the group are discounted" (`app/Enums/PromoType.php:95-98` — the only parameter columns are `promo_price`, `required_quantity`, `second_item_price`).

#### A.2 Real leaflets produce N=3 for both mechanics

Recorded verbatim Lidl tiles, `tests/Fixtures/Ingestion/lidl-tiles.txt:24-34`:

```
Czekolada mleczna
z bakaliami
100 g
2+1 gratis
Cena poza promocją: 4,49/opak.
---
JACOBS
Café Crema lub Espresso, kawa ziarnista
1 kg
Trzeci, najtańszy za grosz.
Cena poza promocją: 89,99/opak.
```

The parser turns both into N=3 — verified verbatim at `app/Ingestion/Drivers/Lidl/PdfTextParser.php:326-370`:

- `mechanic()`: `/gratis|\b\d\s*\+\s*\d\b/iu` → `PromoType::OnePlusOne`; `/za\s+grosz|za\s+złotówkę/iu` → `PromoType::SecondForFixed` (matched first).
- `requiredQuantity()`: `preg_match('/\b(\d)\s*\+\s*(\d)\b/u')` → `return (int) $matches[1] + (int) $matches[2];` → **2+1 = 3**.
- `requiredQuantity()`: `preg_match('/trzeci/iu', $tile) => 3`.

The hand-seed never contained N=3 for these mechanics — `database/seeders/ExampleBasketSeeder.php:183-184` (`OnePlusOne`, `required_quantity => 2`) and `:305-306` (`SecondForFixed`, `required_quantity => 2`). The only N=4 row is `ConditionalUnitPrice` (`:290-292`), whose formula is N-agnostic and correct.

This is the risk statement's exact wording: *a leaflet shape the hand-seed never contained*.

#### A.3 Measured mispricing

Run against the real code (`PromoCalculator` over an unsaved `PriceEntry`, no DB needed):

| Mechanic | N | regular | second | qty | Engine | Till | Error |
|---|---|---|---|---|---|---|---|
| `one_plus_one` ("2+1 gratis") | 3 | 3.00 | 0.00 | 3 | **3.00** | 6.00 | −50% |
| `one_plus_one` | 3 | 3.00 | 0.00 | 6 | **6.00** | 12.00 | −50% |
| `one_plus_one` | 3 | 3.00 | 0.00 | 4 | **6.00** | 9.00 | −33% |
| `second_for_fixed` ("Trzeci … za grosz") | 3 | 89.99 | 0.01 | 3 | **90.01** | 179.99 | −50% |
| `one_plus_one` (classic, control) | 2 | 3.49 | 0.00 | 1,2,3,4 | 3.49 / 3.49 / 6.98 / 6.98 | same | **correct** |
| `conditional_unit_price` (control) | 6 | 2.99 | promo 1.99 | 1,5,6,7,12 | 2.99 / 14.95 / 11.94 / 14.93 / 23.88 | same | **correct** |
| `loyalty_card` (control) | — | 8.49 | promo 6.49 | 1,3 | 6.49 / 19.47 | same | **correct** |

`PromoType::valueViolations()` returns `[]` for the N=3 `one_plus_one` row — **the validation gate does not reject it**. `valueViolations()` only rejects `required_quantity < 2` (`app/Enums/PromoType.php:128-131`); there is no upper bound and no consistency check against the mechanic's meaning.

#### A.4 The verdict flips — proven end-to-end

Using the real chocolate tile (Lidl, `2+1 gratis`, regular 4,49) against a plain Biedronka promo price of 2,80, basket quantity 3, through the actual `BasketComparator` on a real database:

```
ENGINE  Lidl=4.49  Biedronka=8.40  -> verdict: winner probe-lidl
TILL    Lidl=8.98 (2+1 gratis: pay 2 of 3)  Biedronka=8.40  -> truth: taniej w probe-bied
```

The engine names **Lidl**; the shopper pays less at **Biedronka**. This is Risk #1 in full, and it defeats the PRD's central guardrail — *"Werdykt nie kłamie"* (`prd.md` §Success Criteria/Guardrails). Note that the guardrail machinery cannot help here: the row is complete, fresh and trusted. It is simply priced wrong.

#### A.5 Why the existing tests miss it

`tests/Feature/Pricing/BasketComparatorTest.php:155` — the shared `listing()` helper creates **only** the `lidl` network. Consequence: all six mandatory-mechanic tests assert `resultFor('lidl')->total` and **none of them exercises a verdict**. The mandated protection in `CLAUDE.md` ("each must have a PHPUnit test asserting the computed basket total") is satisfied literally while leaving the failure the risk names — a wrong *verdict* — entirely uncovered.

Quantity coverage per mechanic across the whole suite:

| Mechanic | Quantities tested | N values tested | Gap |
|---|---|---|---|
| `simple` | 1, 2 | — | — |
| `one_plus_one` | 1, 3, 4 | **2 only** | **N=3 (real) untested**; qty=2 happy path untested |
| `second_for_fixed` | 2 (clamp only), 3, 4 | **2 only** | **N=3 (real) untested**; qty=1 untested; qty=2 with a real grosz price untested |
| `loyalty_card` | **1 only** | — | `card_price × N` never verified; no clamp test |
| `conditional_unit_price` | 2, 3, 4, 6 | **3 only** | N=2 and N=6 (both real) untested |

Only one test in the suite asserts a verdict flip — `BasketComparatorEdgeCasesTest.php:172-174` — and it is driven by the card/no-card scenario switch, not by a quantity-driven mechanic.

### B. Verification of the Risk #1 response guidance

| Guidance line | Verdict | Evidence |
|---|---|---|
| "the computed total equals what a shopper actually pays at the till" | **Correct and now sharply motivated** | §A.3, §A.4 |
| "**forced overbuy included**" | **Wrong as written — must be corrected** | See §B.1 |
| "challenge that the five mechanics are protected because five tests exist" | **Confirmed, and worse than suspected** | §A.5 — the five tests cannot produce a verdict at all |
| "challenge that a total asserted against SQLite also holds on MySQL 8.0" | **Disproved for arithmetic; real elsewhere** | See §B.2 |
| "avoid an expected total derived by the same arithmetic as the code under test" | **Already satisfied — not the gap** | See §B.3 |
| "avoid one happy-path total per mechanic and nothing else" | **Confirmed as the actual gap** | §A.5 quantity matrix |

#### B.1 "Forced overbuy included" is not the contract — overbuy is a *disclosure*, not a total component

`PromoCalculator`'s stated contract (`app/Pricing/PromoCalculator.php:12-15`):

> the shopper is charged for exactly the quantity they asked for … **Nothing is ever added to the basket to "unlock" a promo**, so a total never includes an item nobody wanted and never overstates the saving.

The PRD agrees (`prd.md` §Business Logic):

> przy ilości nie będącej wielokrotnością N — cenę obniżoną za pełne wielokrotności i regularną za resztę. Kupujący jednego jogurtu nie dostaje ceny za sześć, **a raport pokazuje, ile sztuk trzeba było dołożyć**

So the till-total oracle must **not** add overbuy units. The report must *disclose* them. A Phase 1 test that adds forced overbuy into an expected total would assert the opposite of the PRD.

Two real gaps follow, both worth recording for Phase 2 rather than fixing here:

- The domain carries only `public bool $promoRequiredMoreItems` (`app/Pricing/LineCost.php:24`). A boolean **cannot express "ile sztuk trzeba było dołożyć"**. The view compensates by rendering the raw threshold — `resources/views/components/comparison-report.blade.php:143-146`, `Promocja wymaga min. {{ $price->entry->required_quantity }} szt.` — which states the threshold, not the shortfall, and is covered by no test.
- The flag is **silently discarded by the cheapest-wins tie-break**. `BasketComparator::priceLine()` (`:150-156`) prefers a lower `simplicity()` rank on an exact tie; a conditional entry that did not fire costs exactly `regular × qty`, so a plain `None` entry at the same price wins the tie (`simplicity(None)=0 < simplicity(ConditionalUnitPrice)=3`) and `promoRequiredMoreItems` becomes `false`. The disclosure vanishes on precisely the listings the seed builds this way (`ExampleBasketSeeder.php:280-292` pairs a `None` row with a `ConditionalUnitPrice` row on one listing).

#### B.2 The SQLite/MySQL challenge — disproved for arithmetic, real for constraints

**Arithmetic: no divergence is possible.**

- No money arithmetic happens in SQL. A grep for `selectRaw|DB::raw|withSum|->sum(|whereRaw|orderByRaw|getRawOriginal` across `app/` and `database/` returns exactly one hit — `BasketComparator.php:108`, which calls the private PHP method `sum()` (`:195-202`, an `array_reduce` over `Money::plus()`).
- `Money` (`app/Pricing/Money.php`) holds a decimal **string**, has a `private` string-only constructor, and calls `bcadd`/`bcsub`/`bcmul`/`bccomp` at an explicit `SCALE = 2` on every operation. No float, no division, no `round()`.
- The `decimal:2` cast normalises whatever PDO returns: `HasAttributes::asDecimal()` → `(string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp)`.

Measured through both engines (same value inserted, read back, cast):

| Inserted | SQLite raw | SQLite cast | MySQL raw | MySQL cast |
|---|---|---|---|---|
| `4.99` | `4.99` (double) | `4.99` | `'4.99'` | `4.99` |
| `1.995` | `1.995` (double) | `2.00` | `'2.00'` | `2.00` |
| `0.125` | `0.125` (double) | `0.13` | `'0.13'` | `0.13` |
| `2.675` | `2.675` (double) | `2.68` | `'2.68'` | `2.68` |
| `0.005` | `0.005` (double) | `0.01` | `'0.01'` | `0.01` |
| `10.00` | `10` (**integer**) | `10.00` | `'10.00'` | `10.00` |

**After the cast the two engines agree on every value that fits the column.** SQLite stores unrounded and MySQL rounds at write, but the half-up cast converges them. A basket total asserted on SQLite does hold on MySQL.

(Confirmed separately: `ini_get('bcmath.scale')` is `0` in the container and `bcadd('3.49','0.00')` returns `'3'` — the hazard `Money.php:9-20` documents is real, and `Money` is the only place that guards it.)

**Constraints: the divergence is real, and it belongs to Risk #6.** Laravel's SQLite grammar emits a bare `numeric` column, dropping precision and scale entirely (`SQLiteGrammar.php:851-854`) where MySQL emits `decimal(8, 2)`. Measured:

| Row shape | SQLite `:memory:` | MySQL 8.0 (strict) |
|---|---|---|
| `required_quantity = 300` (column is `unsignedTinyInteger`) | **ACCEPTED** (stored 300) | **REJECTED** — SQLSTATE 22003 / 1264 |
| `regular_price = 1000000.00` (column is `decimal(8,2)`) | **ACCEPTED** | **REJECTED** — SQLSTATE 22003 / 1264 |
| `regular_price = -5.00` | ACCEPTED | ACCEPTED (both — `decimal` is signed; nothing guards a negative regular price) |

This is directly reachable from the ingestion path: `app/Ingestion/LeafletIngestor.php:153` writes `$offer->regularPrice ?? '0.00'` for flagged rows and the gate never blocks the write, and `tests/Feature/Ingestion/PriceEntryGateTest.php:117-130` deliberately exercises a `1799.00` misread of `17,99` — one more misplaced decimal point (`179900.00`) is stored happily in a SQLite test and throws in production.

#### B.3 The oracle-failure anti-pattern is already avoided in Pricing

Every basket total in all three pricing test files is a **hard-coded string literal** with a hand-derived comment, e.g. `BasketComparatorTest.php:51` — `// 4 items = 2 pairs, each pair costs one regular price: 2 × 3,49 zł`. `BasketComparatorTest.php:104-106` even documents an explicit anti-mirror choice: the numbers are picked so that reusing the other conditional formula would yield `14,00` and fail. **This anti-pattern is not the Phase 1 gap** and the plan should stop treating it as the headline concern for #1.

Three weaker oracles do exist and are worth naming, all outside Pricing:

- `PricePromoSeedTest.php:111-134` validates seeded rows against `PromoType::requiredParameters()` / `forbiddenParameters()` — **the oracle is the implementation under test**. A wrong contract in the enum passes.
- `LeafletValidityScopeTest.php:66-73` asserts two `validOn()` calls equal each other — passes if both return `0`.
- `BasketComparatorEdgeCasesTest.php:303` asserts `assertLessThanOrEqual(8, $queries)` — a magic threshold with no oracle, in a test that asserts neither total nor verdict.

### C. Risk #6 — fixture integrity

#### C.1 The `lessons.md` rule is violated in the suite today

`database/factories/PriceEntryFactory.php:24-40` fixes the original bug for the default path:

```php
'leaflet_id' => Leaflet::factory(),
// Inherit the leaflet's chain. Building both children independently gave each its own
// Network, so a default row put a Lidl price inside a Biedronka leaflet — a shape no
// production data can have and one the schema cannot forbid.
'network_product_id' => fn (array $attributes): NetworkProduct => NetworkProduct::factory()->create([
    'network_id' => Leaflet::query()->whereKey($attributes['leaflet_id'])->value('network_id'),
]),
```

But the derivation is **one-directional**. Pinning the *listing* with `->for()` overwrites that closure and leaves `leaflet_id => Leaflet::factory()` untouched, which spawns a fresh `Leaflet` with a fresh `Network`. Measured against the real factories:

```
default create()               : leaflet.net=7  listing.net=7  -> COHERENT
->for(listing,'networkProduct'): leaflet.net=9  listing.net=8  -> *** CROSS-CHAIN ***
```

**This shape is live in the suite right now**, at `tests/Feature/Ingestion/PriceEntryGateTest.php:120-126` and `:138-144`:

```php
$listing = NetworkProduct::factory()->create();
PriceEntry::factory()->for($listing, 'networkProduct')->create([
    'regular_price' => '17.99',
```

It does not change those two assertions today (the gate queries only by `network_product_id`, `PriceEntryGate.php:103-107`), but it is exactly the shape `lessons.md` forbids, and `PriceEntryFactoryTest` does not cover this direction. This is the answer to the plan's "must challenge — that a passing suite implies valid fixtures": **the suite is green and the fixtures are already invalid.**

Two further weaknesses:

- **The derivation is order-dependent.** It reads `$attributes['leaflet_id']`, which is resolved before the closure runs only because it is listed first in `definition()`. Reorder the keys and `whereKey(Factory)` yields `null`. Nothing pins the order. Laravel's order-independent idiom is `->recycle($network)`.
- **`conditionalUnitPrice()` is missing from the coverage.** `tests/Feature/Database/PriceEntryFactoryTest.php:33-38` lists `simple()`, `onePlusOne()`, `secondForFixed()`, `loyaltyCard()` — the fifth mechanic's factory state, added for Lidl's dominant mechanic, has **no coherence guard at all**.

Other factories were checked and are clean: `NetworkProductFactory`, `SavedBasketItemFactory`, `LeafletFactory`, `SavedBasketFactory`, `OauthIdentityFactory` all have either a single parent or two genuinely unrelated parents (a `Product` is chain-neutral; a `SavedBasket` belongs to a `User`).

#### C.2 A second fixture shape production cannot hold: the single-network basket

`BasketComparator::decide()` (`:242-244`):

```php
if ($runnerUp === null) {
    return Verdict::winner($cheapest->network, Money::zero());
}
```

With one chain in the database this announces a **winner with a zero margin** — a verdict over nothing. Production always holds two chains (PRD §Non-Goals fixes MVP at Lidl + Biedronka; `PricePromoSeedTest.php:34` asserts `Network::count() === 2`), so a one-chain fixture is a shape production cannot hold.

`BasketComparatorEdgeCasesTest.php:177-206` relies on it — asserting `VerdictType::Winner` with only `lidl` seeded. This is where Risks #1 and #6 meet: **the single-network fixture is the direct reason the five mandatory tests could never have caught the N=3 mispricing.** With one chain there is no verdict to be wrong.

#### C.3 Correction to `lessons.md`

`context/foundation/lessons.md:8` states the cross-chain shape is "a shape production cannot hold and **no composite foreign key can forbid**". The second half is inaccurate: denormalising `network_id` onto `price_entries` and adding `unique(id, network_id)` to both `leaflets` and `network_products` makes `FOREIGN KEY (leaflet_id, network_id) REFERENCES leaflets(id, network_id)` expressible on InnoDB *and* on SQLite. It is a schema-cost decision, not an impossibility. Worth amending so a future reader does not treat the constraint as unavailable.

Confirmed constraints that do exist on `price_entries` (`2026_07_25_120005_create_price_entries_table.php:30-44`): two single-column FKs with `cascadeOnDelete`, and `unique(['leaflet_id','network_product_id','promo_type'])`. No CHECK constraints anywhere — `:21-24` explains why (not portable between MySQL 8 and SQLite). So the promo parameter matrix and every value invariant live in PHP only.

## Cheapest useful test layer

The plan proposes *integration (real DB, fixture-driven)* for Risk #1. Research disagrees on the first half: **the mechanic matrix needs no database at all.**

| What to prove | Cheapest layer | Why |
|---|---|---|
| Mechanic × N × quantity till-totals (the §A.3 matrix) | **Unit** — `PromoCalculator` over an unsaved `PriceEntry` | The calculator is pure: it reads attributes and returns a `LineCost`. All measurements in §A.3 were produced this way with no DB. Runs in milliseconds and permits a wide parameterised matrix. |
| The verdict names the right chain | **Integration** (real DB, **two networks**) | Needs `Network`, `Product`, `NetworkProduct`, `Leaflet`, `PriceEntry` and the eager-load path. Keep this set small — a handful of cases, each a deliberate near-tie where the mechanic decides the winner. |
| Factory rows are coherent (chain agreement) | **Integration** (SQLite is sufficient) | Cross-chain coherence is an application invariant; SQLite can see it. Must cover `create()`, **every** state including `conditionalUnitPrice()`, **and** the `->for(..., 'networkProduct')` direction. |
| Factory rows are a shape production can hold (range/type) | **Integration against MySQL 8.0** | The only layer that can catch it — SQLite has no `decimal(8,2)` and no `unsignedTinyInteger` range (§B.2). |

On the MySQL lane: `ddev describe` shows the `db` container running **mysql:8.0** already. A narrow lane for one fixture-integrity test class is a connection override, not new infrastructure. §7's exclusion ("a second MySQL test lane costs more than it returns") was written about the *whole suite* and remains right for that; it should be narrowed rather than deleted, because Risk #6 has no other honest layer.

## Flags: speculative risks and misleading evidence

1. **The hot-spot commit counts are overstated ~3-4×.** The plan cites `app/Pricing` at 17 commits/30d; `git log --since=30.days --oneline -- app/Pricing` returns **4**. Likewise `database/factories` 7 → **5**, `app/Models` 10 → **6**. The figures look like file-level changes counted as commits. This does not change the ranking — both risks are now confirmed by direct evidence — but the numbers should not be re-quoted as commits.

2. **The "94 × przy zakupie" figure cannot support a test matrix.** Only **three** distinct N values are recorded anywhere in the repo: **2** (`context/research/vision.md:104`), **3** (`lidl-tiles.txt:11`), **6** (`lidl-tiles.txt:19`). The distribution behind the 94 was never captured. Use {2, 3, 6} as *observed* values; do not claim real-world frequency.

3. **Gold-set mechanic distribution is not evidence about the leaflet.** `measurement.md:37` is explicit: *"Recall of 100% means nothing here"* — the gold set was pre-filled with the model's own first reading, so it cannot contain offers the model missed. That Biedronka produced no `simple` and no `conditional_unit_price` says nothing about the leaflet.

4. **`conditional_unit_price` is currently unreachable in production data.** Lidl never labels the conditional promo price (`lidl-tiles.txt:16-22` — a regular price, an N, and no promo price), so the parser leaves `promo_price = null`, the gate flags the row, and `PromoCalculator::conditionalUnitPrice()` returns `null` at `:117` → "brak danych". Any Phase 1 test for this mechanic is necessarily synthetic. That is fine — but the plan should not describe it as protecting real data today.

5. **The remainder rule has no observed-transaction oracle.** The leaflet says `lub wielokrotności 6 opak.` (`lidl-tiles.txt:20`), which establishes that the price applies at N, 2N, 3N — it does **not** state what the till charges for the 7th item. The "full multiples discounted, remainder at regular" rule is PRD fiat (`prd.md` §Business Logic), implemented at `PromoCalculator.php:129-132`. Defensible and internally consistent, but it is a product decision, not a measured fact. Worth a line in the plan so a future reader does not mistake it for evidence.

6. **Roughly half of all real ingested rows are flagged and invisible** (`measurement.md:49` — 10 Biedronka rows written, 5 trusted). Phase 1 fixtures should set `needs_review => false` explicitly rather than relying on the default, or the tests will drift from what real data looks like.

## Open Question — needs a decision before `/10x-plan`

**How should `required_quantity` be interpreted for `one_plus_one` and `second_for_fixed`?**

The sources resolve what the *shopper pays* unambiguously — "2+1 gratis" means buy 3, pay for 2 — but they do not resolve **how the schema should express it**. Two coherent options:

- **(a) Add a parameter** (e.g. `discounted_quantity`, = 1 for "2+1", = 1 for "Trzeci … za grosz") and price a group as `regular × (N − discounted) + second × discounted`. Correct for every N; costs a migration, a gate rule, a parser change and a seeder update.
- **(b) Redefine `required_quantity` as "items paid at full price"** and make the parser emit 2 for "2+1 gratis". No migration; but it silently changes the meaning of existing rows and of `conditional_unit_price`, which uses the same column with the *other* meaning.

This is a design decision with a schema cost, so it belongs in `/10x-plan`, not here.

Note the process boundary: `CLAUDE.md` §Lesson boundaries reserves the bug → fix → regression-test workflow for Lesson 5. Phase 1's remit is to make the failure *assertable*. The most defensible reading is that Phase 1 writes the till-total matrix from the oracle (leaflet text + PRD), which will fail red on the N=3 cases, and the plan then decides explicitly whether fixing the formula is in scope for this phase or is handed to a separate change.

## Code References

- `app/Pricing/PromoCalculator.php:71-97` — `conditional()`, the defective group formula (correct only at N=2)
- `app/Pricing/PromoCalculator.php:64-69` — docblock revealing the N=2-only reasoning
- `app/Pricing/PromoCalculator.php:113-135` — `conditionalUnitPrice()`, correct at every N tested
- `app/Pricing/PromoCalculator.php:12-15` — "nothing is ever added to the basket to unlock a promo"
- `app/Pricing/BasketComparator.php:150-156` — cheapest-wins tie-break that discards `promoRequiredMoreItems`
- `app/Pricing/BasketComparator.php:242-244` — single-network winner with a zero margin
- `app/Pricing/Money.php:23-73` — BCMath-on-strings, explicit `SCALE = 2`, no float
- `app/Enums/PromoType.php:114-153` — `valueViolations()`; no upper bound on `required_quantity`
- `app/Ingestion/Drivers/Lidl/PdfTextParser.php:326-370` — `mechanic()` and `requiredQuantity()`; `2+1` → 3, `trzeci` → 3
- `app/Ingestion/LeafletIngestor.php:153` — flagged rows written with raw parser output
- `app/Ingestion/Validation/PriceEntryGate.php:103-123` — the one uncast money read (`->value()`), float on SQLite / string on MySQL
- `app/Models/PriceEntry.php:49-61` — `decimal:2` casts; `usableOn()` at `:100-104`
- `database/factories/PriceEntryFactory.php:24-40` — one-directional, order-dependent parent derivation
- `database/seeders/ExampleBasketSeeder.php:183-184`, `:290-292`, `:305-306` — seeded N values (2, 4, 2)
- `database/migrations/2026_07_25_120005_create_price_entries_table.php:21-24`, `:30-44` — no CHECK constraints, and why
- `tests/Fixtures/Ingestion/lidl-tiles.txt:16-34` — the real Lidl tiles that drive §A.2
- `tests/Feature/Pricing/BasketComparatorTest.php:155` — the single-network helper behind §A.5
- `tests/Feature/Database/PriceEntryFactoryTest.php:33-38` — states list missing `conditionalUnitPrice()`
- `tests/Feature/Ingestion/PriceEntryGateTest.php:120-126`, `:138-144` — the live cross-chain fixtures
- `resources/views/components/comparison-report.blade.php:143-146` — threshold rendered, shortfall not

## Architecture Insights

- **The trust and freshness guardrails are structurally sound and are not the weak point.** `PriceEntry::usableOn()` is deliberately indivisible (`validOn()` + `needs_review = false`) and is the sole production read path. The guardrail protects against *absent* and *untrusted* data; it has no defence against data that is present, fresh, trusted and **arithmetically mispriced**. Phase 1 is exactly the layer that covers that.
- **Every invariant that matters lives in PHP.** No CHECK constraints exist by deliberate choice (MySQL/SQLite portability), so the promo parameter matrix, the value rules and chain coherence are all application-level. That is a coherent design, but it means fixtures can only be validated by tests — which is precisely Risk #6's premise.
- **`Money` is the one piece of this codebase that is defensively correct against a real environment hazard** (`bcmath.scale = 0`, verified). The pricing bug is not in the arithmetic; it is in the domain model above it.

## Historical Context (from prior changes)

- `context/archive/2026-08-30-leaflet-vision-ingestion/measurement.md` — vision price accuracy 90.9%, the adopt-with-mandatory-gate branch; the honest caveat that recall is a tautology and the gold set flatters the model. Also records that ~half of ingested rows are flagged.
- `context/archive/2026-08-30-conditional-unit-price-mechanic/plan.md:176` — already recorded that every ingested `conditional_unit_price` row carries a null `promo_price` and surfaces as "brak danych". This research confirms it still holds and elevates it to a flag on the plan's framing.
- `context/archive/2026-07-25-price-promo-data-model-seed/` — the hand-seed whose N ∈ {2, 4} shape is the direct cause of the blind spot in §A.2.
- `context/foundation/lessons.md` — the factory-parent rule; §C.1 shows it is violated again, and §C.3 corrects its claim about composite foreign keys.

## Related Research

- `context/archive/2026-08-30-leaflet-vision-ingestion/research.md` — the ingestion-side oracle work this builds on
- `context/foundation/test-plan.md` §2, §4, §7 — the risk map, stack and exclusions this research verifies

## Recommendations for `/10x-plan`

1. **Phase the work as unit-then-integration**, not integration-only: a parameterised `PromoCalculator` matrix (mechanic × N ∈ {2,3,4,6} × qty ∈ {1, N−1, N, N+1, 2N}) with till-totals derived from leaflet text and the PRD, then a small set of two-chain verdict tests where the mechanic decides the winner.
2. **Ban single-network fixtures** in any test that asserts a verdict; treat the two-chain shape as the fixture-integrity invariant it is.
3. **Close the factory gaps**: cover `->for(..., 'networkProduct')`, add `conditionalUnitPrice()` to the states list, and consider `->recycle()` to remove the ordering dependency.
4. **Add one narrow MySQL-lane test class** for fixture range/type validity; leave the rest of the suite on SQLite and narrow §7's exclusion accordingly.
5. **Resolve the Open Question above before writing the N≠2 assertions**, since it determines whether they are written against option (a) or option (b).
6. **Update `context/foundation/lessons.md`** with the `->for()` escape hatch and the composite-FK correction.
