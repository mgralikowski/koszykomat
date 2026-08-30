# Conditional Unit Price — the Fifth Promo Mechanic — Plan Brief

> Full plan: `context/changes/conditional-unit-price-mechanic/plan.md`

## What & Why

Teach the pricing engine the mechanic Polish leaflets actually lead with: a per-unit price that applies only from N items up — "cena za 1 opak. przy zakupie 3 opak.". PRD FR-007 gained it as a fifth mechanic on 2026-08-30, after the first real Lidl ingestion showed it appears 94 times in one leaflet against 25 for "gratis" and 8 for "za grosz" combined. Until it exists, the four original mechanics cannot express what the leaflet says, so the guardrail correctly refuses to price most of Lidl's real catalogue and the product shows "brak danych".

## Starting Point

`PromoType` owns the parameter matrix in PHP because it cannot be expressed in DDL portable across MySQL 8 and the test suite's SQLite, and `PromoCalculator` prices each mechanic on a never-throw contract. Six rows already sit in `price_entries` labelled `second_for_fixed` by F-03's parser — the closest of four wrong options — all flagged and invisible to every verdict.

## Desired End State

Three items at a conditional price of 4,00 with a regular of 6,00 cost 12,00; two items cost 12,00 at the regular price with the report saying the promotion did not apply; four items cost 18,00. The mechanic has its own mandatory PHPUnit test, the seeded basket demonstrates it, and F-03's parser emits it instead of mislabelling it.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Schema | No migration | `promo_price` and `required_quantity` already exist; only their permitted *combination* is new, which is exactly what the PHP-side matrix is for. | Plan |
| Formula | Its own branch, not the shared conditional one | Every item in a complete group costs the conditional price here, where 1+1 and second-for-fixed charge one item at full price — reusing their formula would overcharge by nearly a full regular price per group. | Plan |
| Enum + seed together | One phase | `PricePromoSeedTest` iterates `PromoType::cases()` and demands each be seeded, so splitting them leaves the suite red between phases. | Plan |
| Parser mapping | Its own phase | It edits F-03's uncommitted, in-flight work; a separate commit keeps the two changes legible. | Plan |
| Mislabelled rows | Left alone | All six are flagged and unreachable by any verdict, and the next ingestion overwrites them. | Plan |
| UI work | None | `LineCost::promoRequiredMoreItems` and its Blade rendering already cover the did-not-apply case. | Plan |

## Scope

**In scope:** `PromoType::ConditionalUnitPrice` with its parameter contract, value invariants and Polish label; the pricing formula in `PromoCalculator`; the `simplicity()` match arm in `BasketComparator`; a factory state; the fifth mandatory mechanic test; a seeded example; F-03's Lidl parser mapping.

**Out of scope:** any migration or new column; changes to the existing conditional formula; a cleanup migration for the six mislabelled rows; renaming `SecondForFixed`; any UI or report work; a roadmap item for this change; the archived F-01 and S-01 plans.

## Architecture / Approach

The mechanic slots into the shape the four existing ones established: a case in `PromoType`, its required and forbidden parameters, its value invariants, a branch in `PromoCalculator` and a factory state. What makes it different is the group cost — `promo_price × required_quantity` rather than `regular + second_item_price × (required_quantity − 1)` — and that difference is the one thing a reviewer should check hardest, because reusing the existing formula would produce a plausible, wrong, silently-larger total. Everything downstream is already generic: `isConditional()` derives from the parameters, the overbuy warning derives from `LineCost`, and the report renders whatever `label()` returns.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. The mechanic in the domain | Enum case, formula, factory state, fifth mandatory test, seeded example, updated exhaustive matches | Two `match` expressions over `PromoType` fail at *runtime*, not parse time; and the seed change touches the homepage demo the north star ships |
| 2. Correct F-03's parser mapping | Lidl parser emits the new mechanic instead of `second_for_fixed` | Edits another change's uncommitted work, so the two must not be conflated in one commit |

**Prerequisites:** PRD FR-007 v2 and the matching `CLAUDE.md` rule (both landed 2026-08-30). Phase 2 assumes F-03 phase 2's parser is present in the working tree.
**Estimated effort:** ~1 session across 2 phases; phase 2 is a single method.

## Open Risks & Assumptions

- **The seed change can move the demo.** The comparator picks the cheapest reachable entry per listing, so a conditional price that undercuts the current cheapest at the seeded quantity would flip the homepage verdict. The plan requires choosing numbers that leave it unchanged, and `HomePageTest` is the net.
- **The two exhaustive matches fail at runtime.** `PromoType::label()` and `BasketComparator::simplicity()` throw `UnhandledMatchError` when they meet an unhandled case — nothing catches this at parse time, and it would surface as a 500 on the report rather than a failing build.
- **Phase 2 depends on uncommitted work.** F-03 phase 2 is in the working tree, not in history; if it is reverted or reworked, phase 2's target moves with it.
- **The mechanic's real-world shape may be broader than "przy zakupie N".** Lidl also prints "8 w cenie 4" and "-60% przy zakupie 3", which are arguably the same mechanic wearing different clothes; this change handles the stated-unit-price form and leaves the percentage form to be observed on more leaflets before being modelled.

## Success Criteria (Summary)

- A basket line priced under this mechanic pays the conditional price only for complete multiples of the required quantity, and the regular price for everything else.
- Buying fewer than the required quantity is priced at the regular price and says so in the report.
- All five mechanics have a passing PHPUnit test asserting a computed basket total, satisfying the `CLAUDE.md` rule.
