# Test Rollout Phase 1 — Verdict Correctness on Real Leaflet Shapes — Plan Brief

> Full plan: `context/changes/testing-verdict-correctness/plan.md`
> Research: `context/changes/testing-verdict-correctness/research.md`

## What & Why

Rollout Phase 1 of `context/foundation/test-plan.md` covers Risk #1 (a promo mechanic mispriced on a shape the hand-seed never contained, flipping the verdict) and Risk #6 (fixtures encoding a shape production cannot hold).

Research turned #1 from a hypothesis into a measured defect: `PromoCalculator::conditional()` is correct **only at `required_quantity = 2`**, and real Lidl leaflets print N=3 for both `one_plus_one` ("2+1 gratis") and `second_for_fixed` ("Trzeci, najtańszy za grosz"). On the real chocolate tile at quantity 3 the engine names Lidl the winner while the till makes Biedronka cheaper. This phase makes that assertable without fixing it.

## Starting Point

The five mandatory mechanic tests all seed **one network**, so they assert a total and can never produce a verdict — which is why a mispricing this large went unnoticed. `one_plus_one` and `second_for_fixed` are tested at N=2 only, the exact value where the buggy formula happens to be right. On the fixture side, `PriceEntryFactory` derives the listing's chain from the leaflet, but `->for(..., 'networkProduct')` silently overrides it — two live tests already build cross-chain rows — and the two states that would express N=3 hard-code their threshold at 2.

## Desired End State

Every mechanic has a till-total assertion at each threshold real leaflets print, with expected values traced to leaflet text and the PRD rather than to the engine's own arithmetic. The N=3 mispricing is *measured*: `composer test:all` prints the engine-vs-till diff per case, and those cases go green on their own when the defect is fixed. `composer test` stays green so it remains a usable pre-push gate. No factory path can produce a cross-chain row, and every factory state's row is proven insertable into MySQL 8.0.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| Scope of the defect | Tests only; fix is a separate change | The fix needs a schema decision; `CLAUDE.md` reserves bug→fix→regression for Lesson 5 | Research |
| Red tests vs the gate | `known-defect` group excluded from `composer test` | Gate stays useful for other regressions, yet the cases really execute and self-heal on fix | Plan |
| Matrix structure | `#[DataProvider]` | `CLAUDE.md` prescribes parameterised tests over redundant copies; every case reports independently | Plan |
| Group granularity | Split each mechanic into a green method and a grouped method | Groups apply per method — a mixed provider would exclude passing cases too | Plan |
| MySQL lane | Dedicated `koszykomat_test` schema, `mysql` group, opt-in | Only layer that sees a row MySQL rejects; ddev already runs MySQL 8.0 | Plan |
| Lane wiring | Env vars + `--group`, no `phpunit.xml` change | Verified a shell env var overrides `phpunit.xml`'s `<env>` block | Plan |
| Factory defect | Fixed here, not deferred | It is test infrastructure, not production logic — the line that keeps "tests only" honest | Plan |
| Overbuy | Disclosed, never added to a total | `PromoCalculator` contract and PRD §Business Logic both say charge exactly what was asked | Research |

## Scope

**In scope:** unit till-total matrix across N ∈ {2, 3, 6}; two-chain verdict tests on deliberate near-ties; factory threshold parameters, coherence guard and `forListing()`; MySQL fixture-shape lane; cookbook §6.1/§6.2, §5 gate note, `lessons.md` corrections.

**Out of scope:** fixing `conditional()`; rendered report content (Phase 2); the tie-break that discards the overbuy flag (Phase 2); the single-network winner rule; ingestion parsers (Phase 5); moving the suite to MySQL.

## Architecture / Approach

Two defects, treated differently — a **production** defect (`conditional()` at N≠2) is documented via red tests in an excluded group; a **test-infrastructure** defect (the factory's `->for()` escape hatch) is fixed outright and then asserted green. Layering follows cost × signal: the mechanic matrix runs as unit tests because `PromoCalculator` is pure over an unsaved model and needs no database; the verdict needs two chains and a real DB; coherence runs on SQLite; range/type validity runs on MySQL 8.0.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Test infrastructure | Threshold params, coherence guard, `forListing()`, four composer lanes | The guard breaks two existing tests — must land with their migration in one step |
| 2. Till-total matrix (unit) | Every mechanic × threshold × boundary quantity, no DB | Writing an expectation with the engine's own formula would reproduce the bug |
| 3. Verdict near-ties (integration) | Threshold pivot flips the winner; the real Lidl flip lands red | A one-chain fixture would yield a hollow winner |
| 4. Fixture integrity | Coherence across all five states + `forListing()`; MySQL acceptance and rejection | A MySQL lane with no rejection case would be vacuously green |
| 5. Cookbook and lessons | §6.1, §6.2, §5 gate note, two `lessons.md` corrections | Leaving green-means-complete unstated |

**Prerequisites:** ddev running; `composer test:mysql:setup` run once per environment (verified working, including the grant that fails first without it).
**Estimated effort:** ~2–3 sessions across five phases; Phase 2 is the bulk.

## Open Risks & Assumptions

- The remainder rule ("full multiples discounted, remainder at regular") is PRD fiat, not an observed transaction — defensible, but no receipt confirms it.
- The N=6 conditional case uses a real threshold with a **synthetic** promo price, because Lidl never labels one; the companion case asserts the `promo_price = null` shape production is actually in.
- `composer test:all` stays red until the `conditional()` fix lands. If that change is delayed, the red becomes background noise — the §5 note is what keeps it legible.
- Only three thresholds (2, 3, 6) are recorded anywhere; the distribution behind the leaflet's 94 "przy zakupie" hits was never captured.

## Success Criteria (Summary)

- A shopper's till total is asserted for every mechanic at every threshold real leaflets print — and a wrong one fails loudly, naming threshold and quantity.
- A mispriced mechanic can no longer hand the verdict to the wrong chain without a test failing.
- No factory path can build a row production would reject, on either the chain axis or the column-type axis.
