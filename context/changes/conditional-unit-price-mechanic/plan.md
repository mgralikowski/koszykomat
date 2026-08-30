# Conditional Unit Price — the Fifth Promo Mechanic Implementation Plan

## Overview

Teach the pricing engine the mechanic Polish leaflets actually lead with: **a per-unit price that only applies from N items up** — "cena za 1 opak. przy zakupie 3 opak.". PRD FR-007 (v2) added it after the first real Lidl ingestion showed it is that chain's dominant promotion, appearing 94 times in a single leaflet against 25 for "gratis" and 8 for "za grosz" combined.

Without it the four existing mechanics cannot express what the leaflet says, so the guardrail correctly refuses to price the offer and the product shows "brak danych" for most of Lidl's real catalogue — the verdict stays honest and stays empty.

## Current State Analysis

`App\Enums\PromoType` carries five cases (`none`, `simple`, `one_plus_one`, `second_for_fixed`, `loyalty_card`) and owns the parameter matrix in PHP, because — as its own docblock records — MySQL 8 and the in-memory SQLite the suite uses disagree on check constraints, so the matrix cannot be expressed in portable DDL. `App\Pricing\PromoCalculator` prices each mechanic and is built on a never-throw contract: a malformed row yields an unpriceable line, never an exception.

**No migration is needed.** The mechanic's parameters are a discounted per-unit price and a required quantity — `price_entries.promo_price` and `price_entries.required_quantity`, both already present. What is new is the *combination*: today `promo_price` and `required_quantity` are mutually exclusive across every mechanic, and this is the first that needs both. That is precisely the kind of rule the matrix exists to express.

Three existing tests do most of the enforcement work and one of them **forces the scope of this change**:

- `PricePromoSeedTest::test_all_promo_mechanics_are_represented()` iterates `PromoType::cases()` and asserts each appears in the seed. Adding a case turns this red until the seeder carries the new mechanic — so the enum and the seed must land together or the suite is broken between phases.
- `PricePromoSeedTest::test_every_price_entry_matches_its_promo_type_parameter_contract()` will validate the new contract for free.
- `PricePromoSeedTest::test_conditional_mechanics_require_at_least_two_items()` covers it automatically, because `isConditional()` derives from the presence of `required_quantity`.

Two **exhaustive `match` expressions** will break at runtime — PHP throws `UnhandledMatchError`, and nothing catches it at parse time: `PromoType::label()` and `BasketComparator::simplicity()`.

`LineCost::promoRequiredMoreItems` already means exactly what this mechanic needs — *"a conditional mechanic that did not fire because the requested quantity is below its required quantity"* — and `resources/views/components/comparison-report.blade.php` already renders it as *"Promocja wymaga min. N szt. — przy tej ilości nie obowiązuje."* No UI work.

Six rows currently sit in `price_entries` carrying `second_for_fixed` from F-03 phase 2's parser mapping "przy zakupie N" onto the wrong mechanic. All six are `needs_review = true`, so no verdict can see them; they are the offers this change makes representable.

## Desired End State

A basket line whose leaflet entry says "cena za 1 opak. przy zakupie 3 opak." is priced correctly: three items cost three times the conditional price, two items cost twice the regular price with the report saying the promotion did not apply, and four items cost three at the conditional price plus one at the regular one.

The mechanic has its own PHPUnit test asserting a computed basket total, as `CLAUDE.md` requires of all five. The seeded example basket demonstrates it, so the homepage shows it working. F-03's Lidl parser emits it instead of mislabelling it as `second_for_fixed`.

### Key Discoveries:

- No schema change: `promo_price` + `required_quantity` already exist (`database/migrations/2026_07_25_120005_*`)
- `PromoType::isConditional()` derives from `required_quantity` being required — the new case is conditional with no extra wiring (`app/Enums/PromoType.php:73`)
- `LineCost::promoRequiredMoreItems` and its Blade rendering already cover the did-not-apply case
- `PricePromoSeedTest::test_all_promo_mechanics_are_represented()` forces the seed into the same phase as the enum
- `PromoType::label()` and `BasketComparator::simplicity()` are exhaustive matches that fail at runtime, not at parse time
- The four mandatory mechanic tests use `PriceEntry::factory()` states, not the seed (`tests/Feature/Pricing/BasketComparatorTest.php:27-80`)

## What We're NOT Doing

- **No migration and no new column.** The parameters exist; only their permitted combination changes.
- **No change to `PromoCalculator`'s conditional formula for 1+1 or second-for-fixed.** Their shared formula stays exactly as S-01 shipped it.
- **No cleanup migration for the six mislabelled rows.** They are flagged, invisible to every verdict, and the next ingestion overwrites them on the existing upsert key.
- **No renaming of `SecondForFixed`.** Its name implying n=2 is a separate labelling question the F-03 research already parked.
- **No UI or report work.** The mechanic's label and the did-not-apply warning render through paths that already exist.
- **No new roadmap item.** This change has no `Change ID` in `roadmap.md`; whether the milestone gains a row for it is `/10x-roadmap`'s call, not this plan's.
- **No changes to the archived F-01 and S-01 plans** — they record what was delivered when four mechanics were the requirement.

## Implementation Approach

Two phases, split along a boundary that matters more than size.

Phase 1 delivers the mechanic wherever the mechanic belongs: the enum, the formula, the factory, the seed and the tests. It has to be one phase rather than two because an existing seed test iterates `PromoType::cases()` — adding the case without seeding it leaves the suite red, and a phase that ends red is not a checkpoint.

Phase 2 corrects F-03's parser, which currently maps "przy zakupie N" onto `second_for_fixed` because that was the closest of four wrong options. It is one method, but it is in another change's **uncommitted, in-flight** work, so it gets its own phase and its own commit rather than hiding inside this one.

## Critical Implementation Details

**The group cost differs from the two existing conditional mechanics, and that is the whole point.** For 1+1 and second-for-fixed a complete group costs `regular + second_item_price × (required_quantity − 1)` — one item at full price, the rest discounted. Here *every* item in a complete group costs the same conditional unit price, so a group costs `promo_price × required_quantity`. Reusing the existing formula would silently overcharge by nearly the full regular price on every group.

**`Money` is the only arithmetic path.** `bcmath.scale` is 0, so any `bc*` call without an explicit scale truncates silently, and `decimal:2` casts hand back strings that coerce to float. The class docblocks on `PromoType` and `PriceEntry` both state this contract.

## Phase 1: The mechanic in the domain

### Overview

Everything from the enum to the seeded demo, in one phase so the suite is green at both ends.

### Changes Required:

#### 1. The enum case and its contract

**File**: `app/Enums/PromoType.php`

**Intent**: Add the mechanic and declare which parameters it takes, so the matrix that guards every write knows the combination is legitimate.

**Contract**: New case `ConditionalUnitPrice = 'conditional_unit_price'`. `requiredParameters()` returns `['promo_price', 'required_quantity']` for it — the first mechanic to need both, which is why `second_item_price` lands in `forbiddenParameters()` automatically. `label()` gains a Polish user-facing string; keep it short, since it renders as a badge next to the price and the required quantity is already shown separately by the did-not-apply line. `valueViolations()` adds `promo_price < regular_price` and `required_quantity >= 2` for the new case. `isConditional()` needs no change — it derives from `required_quantity` being required.

#### 2. The pricing formula

**File**: `app/Pricing/PromoCalculator.php`

**Intent**: Price the mechanic as the leaflet states it — the discounted unit price applies only from the required quantity up, and only to complete multiples of it.

**Contract**: A new branch in `cost()`, separate from the shared `conditional()` used by 1+1 and second-for-fixed, because the group cost is different: `groups × required_quantity × promo_price + remainder × regular_price`, where `groups = intdiv($quantity, $requiredQuantity)` and `remainder = $quantity % $requiredQuantity`. Returns null when `promo_price` is null or `required_quantity` is null or below 2 — the same never-throw discipline as the existing branches. `LineCost::promoRequiredMoreItems` is `$groups === 0`, matching the existing conditional semantics exactly. All arithmetic through `Money`.

#### 3. Exhaustive matches that break at runtime

**File**: `app/Pricing/BasketComparator.php`

**Intent**: Keep tie-breaking total, since an unhandled case throws `UnhandledMatchError` in production rather than failing at parse time.

**Contract**: `simplicity()` gains the new case. Place it between `LoyaltyCard` and `OnePlusOne`: on an exact tie the plainer explanation should win, and "this price needs N items" is plainer than "buy one get one" but less plain than a straight card price.

#### 4. Factory state

**File**: `database/factories/PriceEntryFactory.php`

**Intent**: Give the mandatory test and any future test a one-liner for this mechanic, matching the four states that already exist.

**Contract**: A `conditionalUnitPrice(string $promoPrice = '…', int $requiredQuantity = 3)` state setting `promo_type`, `promo_price` and `required_quantity`, and leaving `second_item_price` null. Follows the shape of `secondForFixed()` and `loyaltyCard()`. Per `context/foundation/lessons.md`, the state must not create its own parents — the listing derives from the leaflet.

#### 5. The fifth mandatory test

**File**: `tests/Feature/Pricing/BasketComparatorTest.php`

**Intent**: Satisfy the `CLAUDE.md` rule that each of the five mechanics has a PHPUnit test asserting a computed basket total, and pin the three behaviours that distinguish this mechanic from the others.

**Contract**: One test in the existing style, using the new factory state and the file's existing `listing()` helper. It asserts a computed total for: a quantity equal to the required quantity (all items discounted), a quantity below it (all items at the regular price, and `promoRequiredMoreItems` true), and a quantity that is not a whole multiple (complete groups discounted, remainder regular). The numbers must be chosen so a group cost computed with the *old* conditional formula would give a visibly different total — otherwise the test would pass against the bug this change exists to avoid.

#### 6. Seed the mechanic

**File**: `database/seeders/ExampleBasketSeeder.php`

**Intent**: Make the mechanic real in the demo, and keep `PricePromoSeedTest::test_all_promo_mechanics_are_represented()` green — that test iterates `PromoType::cases()`, so a case with no seeded row fails it.

**Contract**: Add one `conditional_unit_price` entry to an existing listing in the catalogue, alongside that listing's current entries — the unique key is `(leaflet_id, network_product_id, promo_type)`, so a listing may carry it next to a regular price. Choose the listing and the numbers so the homepage's existing verdict does not flip: the comparator picks the cheapest reachable entry per listing, so a conditional price below the current cheapest at the seeded basket's quantity would change the demo. Keep the example basket at its current four products.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- The five mandatory mechanic tests pass: `ddev artisan test tests/Feature/Pricing/BasketComparatorTest.php`
- Seed integrity tests pass, including the all-mechanics-represented assertion: `ddev artisan test tests/Feature/Database/PricePromoSeedTest.php`
- Fresh migrate and seed succeed: `ddev artisan migrate:fresh --seed`
- Code style passes: `ddev composer lint`
- No exhaustive `match` over `PromoType` is missing the new case — `grep -rn 'PromoType::SecondForFixed' app/` shows every match arm updated

#### Manual Verification:

- The homepage still names the same winner as before the seed change, with the same totals
- The new mechanic's Polish label renders correctly on the affected line
- A basket quantity below the required quantity shows "Promocja wymaga min. N szt. — przy tej ilości nie obowiązuje"
- A hand-check in tinker confirms 3 items at a conditional price of 4,00 with a regular of 6,00 total 12,00, and 4 items total 18,00

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Correct F-03's parser mapping

### Overview

F-03 phase 2 maps "przy zakupie N" onto `second_for_fixed` because it was the least wrong of four options. Now that the right one exists, point the parser at it. This phase reaches into another change's uncommitted work, which is why it is separate.

### Changes Required:

#### 1. The Lidl parser's mechanic mapping

**File**: `app/Ingestion/Drivers/Lidl/PdfTextParser.php`

**Intent**: Emit the mechanic the leaflet actually states, so the offers currently flagged as malformed become priced rows a verdict can use.

**Contract**: In `read()`, the branch that forces a conditional mechanic when "przy zakupie N" is present now selects `PromoType::ConditionalUnitPrice` rather than `PromoType::SecondForFixed`, and sets `promo_price` from the tile's discounted amount instead of leaving it null. `mechanic()` keeps "za grosz" / "za złotówkę" mapping to `SecondForFixed` — those are a genuinely different mechanic and the leaflet says so explicitly. Offers whose conditional price cannot be read stay null and are flagged by the gate, unchanged.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- Code style passes: `ddev composer lint`
- No parser path still emits `SecondForFixed` for a "przy zakupie" tile — `grep -n 'SecondForFixed' app/Ingestion/Drivers/Lidl/PdfTextParser.php` shows it only under the grosz/złotówka branch

#### Manual Verification:

- A real `ddev artisan leaflets:ingest lidl` writes `conditional_unit_price` rows where it previously wrote flagged `second_for_fixed` ones
- The flagged-row count drops, and the rows that become trusted carry a `promo_price` below their `regular_price`
- Spot-check three ingested conditional offers against the leaflet PDF — the required quantity and the conditional price match what is printed

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

### Unit Tests:

- The mechanic's formula at three quantities: equal to the required quantity, below it, and not a whole multiple
- Numbers chosen so the old conditional formula would produce a visibly different total

### Integration Tests:

- `PricePromoSeedTest` (existing) validates the new parameter contract, the at-least-two-items rule and the all-mechanics-represented assertion with no new test code
- `HomePageTest` (existing) is the regression net for the seed change

### Manual Testing Steps:

1. `ddev artisan migrate:fresh --seed`, then load the homepage — same winner, same totals as before.
2. Find the seeded conditional line in the report; confirm its Polish label.
3. In tinker, price the same entry at a quantity below the required one — total is quantity × regular, and `promoRequiredMoreItems` is true.
4. After phase 2, run `ddev artisan leaflets:ingest lidl` and compare three conditional offers against the leaflet PDF.

## Performance Considerations

None. The mechanic is one more branch in a match already executed per line per chain, over data already eager-loaded; the comparison stays a single query pass.

## Migration Notes

No migration. `promo_price` and `required_quantity` already exist and the new mechanic only uses them in a combination the PHP-side matrix now permits.

The six existing `second_for_fixed` rows from F-03's mapping are left alone: all are `needs_review = true` and therefore invisible to every verdict, and the next ingestion overwrites them on the `(leaflet_id, network_product_id, promo_type)` unique key. Note that a row whose `promo_type` *changes* is written under a different key, so the stale `second_for_fixed` row survives alongside the new one — harmless while flagged, and cleared by any `migrate:fresh --seed`.

## References

- PRD FR-007 (v2) and Business Logic: `context/foundation/prd.md` — the mechanic's definition and its counting rule
- Testing rule: `CLAUDE.md` — five mechanics, each with a PHPUnit test asserting a computed basket total
- Evidence that prompted the mechanic: `context/research/vision.md` §4, plus the first real ingestion in `context/changes/leaflet-vision-ingestion/`
- Existing conditional formula this one deliberately differs from: `app/Pricing/PromoCalculator.php`
- Factory-parent rule: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: The mechanic in the domain

#### Automated

- [x] 1.1 Full test suite passes: `ddev composer test` — 81635a9
- [x] 1.2 The five mandatory mechanic tests pass: `ddev artisan test tests/Feature/Pricing/BasketComparatorTest.php` — 81635a9
- [x] 1.3 Seed integrity tests pass: `ddev artisan test tests/Feature/Database/PricePromoSeedTest.php` — 81635a9
- [x] 1.4 Fresh migrate and seed succeed: `ddev artisan migrate:fresh --seed` — 81635a9
- [x] 1.5 Code style passes: `ddev composer lint` — 81635a9
- [x] 1.6 No exhaustive `match` over `PromoType` is missing the new case — 81635a9

#### Manual

- [x] 1.7 The homepage still names the same winner as before the seed change, with the same totals — 81635a9
- [x] 1.8 The new mechanic's Polish label renders correctly on the affected line — 81635a9
- [x] 1.9 A quantity below the required one shows "Promocja wymaga min. N szt. — przy tej ilości nie obowiązuje" — 81635a9
- [x] 1.10 Hand-check in tinker: 3 items at 4,00 conditional with 6,00 regular total 12,00; 4 items total 18,00 — 81635a9

### Phase 2: Correct F-03's parser mapping

#### Automated

- [x] 2.1 Full test suite passes: `ddev composer test` — 88eef4d
- [x] 2.2 Code style passes: `ddev composer lint` — 88eef4d
- [x] 2.3 No parser path still emits `SecondForFixed` for a "przy zakupie" tile — 88eef4d

#### Manual

- [ ] 2.4 A real Lidl ingest writes `conditional_unit_price` rows where it previously wrote flagged `second_for_fixed` ones
- [ ] 2.5 The flagged-row count drops and newly trusted rows carry a `promo_price` below their `regular_price`
- [ ] 2.6 Three ingested conditional offers spot-checked against the leaflet PDF — quantity and price match
