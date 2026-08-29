<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Guest Fixed Basket Comparison

- **Plan**: `context/changes/guest-fixed-basket-comparison/plan.md`
- **Scope**: Full plan — Phase 1 and Phase 2 (all 20 Progress items `[x]`; commits `98b1e34`, `c79da6f`, epilogue `f5a8411`)
- **Date**: 2026-08-29
- **Verdict**: NEEDS ATTENTION (triaged 2026-08-29 — 4 fixed, 6 skipped)
- **Findings**: 0 critical, 6 warnings, 4 observations

> Note on scope: `app/Pricing/*`, `app/Http/Controllers/HomeController.php`, `tests/Feature/Pricing/*`, `tests/Unit/Pricing/MoneyTest.php` and `tests/Feature/HomePageTest.php` are unchanged since this change and were reviewed at HEAD. `resources/views/home.blade.php` and `resources/views/layouts/app.blade.php` were later reworked by `oauth-authentication` and `basket-builder-comparison-report`; those were reviewed at `c79da6f`, with a note where a finding is still live at HEAD.

## Verification results

| Check | Result |
|---|---|
| `ddev composer test` | PASS — 52 passed, 165 assertions, 1.15 s |
| `ddev artisan test tests/Feature/Pricing tests/Unit/Pricing tests/Feature/HomePageTest.php` | PASS — 29 passed, 64 assertions |
| `ddev composer lint` | PASS — 73 files |
| `ddev composer check-platform-reqs` | PASS — `ext-bcmath 8.5.3 success` |
| `grep -rn "bc*" app/` | 7 hits, all in `app/Pricing/Money.php`, all passing `self::SCALE` |
| `grep -rn "welcome" resources/ routes/ app/` | no matches |
| Manual items 1.8–1.10, 2.6–2.10 | marked `[x]`; corroborated by `HomePageTest` asserting `62,43 zł` / `67,46 zł` / `69,46 zł`, the seeded masło card flip, and the `sm:grid-cols-2` single-column-first layout |

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — A promo price above the regular price is charged without question

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/PromoCalculator.php:41-50, :60-80
- **Detail**: `flatPromoPrice()` returns `promo_price × quantity` unconditionally and `conditional()` returns `regular + second_item_price × (required − 1)` per group unconditionally. Neither compares the promo figure against the `regular_price` on the same row. Cheapest-wins in `priceLine()` (BasketComparator.php:130-150) only rescues this when the listing *also* carries a separate `None` entry — and the seed already contains listings with exactly one entry (`mleko-32-1l` at Lidl has only the `OnePlusOne` row). Failure scenario: FR-006 vision ingestion misreads "2,99" as "29,90" on a `Simple` row for a single-entry listing; the engine charges 29,90 zł, labels it "cena promocyjna" with a valid leaflet window, and flips the whole-basket verdict to the wrong chain with full confidence. This is exactly the wrong-verdict failure the PRD guardrail exists to prevent, and F-03 ingestion is the likeliest source.
- **Fix A ⭐ Recommended**: Clamp per unit — charge `min(promo_price, regular_price)` in `flatPromoPrice()` and `min(second_item_price, regular_price)` per extra item in `conditional()`.
  - Strength: Models what a shopper can actually do (decline the promo, pay shelf price), so the number stays true even on a bad row, and the basket still gets a verdict.
  - Tradeoff: Silently masks a corrupt ingested row instead of surfacing it; the displayed mechanic label would then not match the price charged unless the label is adjusted too.
  - Confidence: HIGH — both call sites already hold `$regular` as a `Money`, so it is a two-line change plus tests.
  - Blind spot: Whether a legitimate mechanic could ever price above regular (e.g. a multi-buy that only beats shelf price in aggregate) — none of the four FR-007 mechanics do.
- **Fix B**: Treat `promo > regular` as a malformed row and return `null`, making the line unpriceable.
  - Strength: Consistent with the existing malformed-row handling at PromoCalculator.php:66; a bad row becomes visible "brak danych" rather than an invisible clamp.
  - Tradeoff: One misread price kills the whole-basket verdict (the no-data rule is whole-basket), so ingestion noise degrades the homepage to "brak danych" more often.
  - Confidence: MEDIUM — correctness is clear, but the user-visible cost depends on how noisy F-03 turns out to be.
  - Blind spot: No data yet on real ingestion error rates.
- **Decision**: FIXED via Fix A — clamp applied in `flatPromoPrice()` (reporting `PromoType::None` when it bites) and in `conditional()`; covered by two new tests in `BasketComparatorEdgeCasesTest`.

### F2 — One chain in the database produces a confident winner with a 0,00 zł margin

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/BasketComparator.php:230-234
- **Detail**: When `$ranked` holds a single `NetworkResult`, `$runnerUp` is null and the method returns `Verdict::winner($cheapest->network, Money::zero())`. The page then renders "Taniej w Lidl" with "Różnica: 0,00 zł" — a comparative claim with nothing compared against it. Reachable in production whenever only one chain has rows (partial ingestion, half-seeded database, one chain's leaflet expired while the other's is current). `decide()` already returns `Verdict::noData([])` for zero networks (:205-207), so the one-network case is the gap in an otherwise correct guardrail.
- **Fix**: Return `Verdict::noData([])` when fewer than two networks produced a result, alongside the existing `$results === []` guard.
- **Decision**: SKIPPED

### F3 — An empty or missing basket config yields "Remis · 0,00 zł" or a 500, not "brak danych"

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/BasketComparator.php:203-238, app/Http/Controllers/HomeController.php:19
- **Detail**: Two connected gaps. (a) With `$lines === []`, every `NetworkResult` gets `unpricedProducts === []`, `sum([])` returns `Money::zero()`, and `decide()` reaches the tie branch — the homepage renders "Remis — W obu sieciach koszyk kosztuje tyle samo" with 0,00 zł per chain, a confident verdict over nothing. (b) `HomeController` passes `config('koszykomat.example_basket')` straight into `compare(array $basket)`; if the key is ever absent — a `config:cache` built before the file was deployed, or a typo — `config()` returns `null` and the `array` parameter type raises a `TypeError`, 500-ing the public landing page rather than degrading. Nothing in the suite covers either path (see F6).
- **Fix**: Return `Verdict::noData([])` when `$lines === []`, and default the controller lookup to `config('koszykomat.example_basket', [])` so a missing key degrades into that same no-data path.
- **Decision**: FIXED — empty-basket guard added in `compareScenario()`, controller lookup defaulted, covered by `test_an_empty_basket_yields_no_data_rather_than_a_tie`. Verified safe for S-02: `BasketController` never compares an empty session basket (`BasketController.php:38`, `:51`).

### F4 — `Money::fromDecimalString` can throw `ValueError`, breaking the never-throw contract

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/Money.php:32-39
- **Detail**: The guard is `is_numeric()`, but `bcadd()` requires a *well-formed* number, which is strictly narrower. `is_numeric('1e3')`, `is_numeric(' 1.5')` and `is_numeric('1.5 ')` are all true, and `bcadd()` on each throws `ValueError: bcadd(): Argument #1 ($num1) is not well-formed` — not the documented `InvalidArgumentException`. `PromoCalculator`'s whole contract ("a malformed row must make a line unpriceable, never throw", PromoCalculator.php:20-22) rests on the exception type it can anticipate. Unreachable from `decimal:2` columns, which is why the suite is green — but `fromDecimalString` is the public entry point for the F-03 ingestion slice, where prices arrive as vision-API strings that may carry whitespace or exponent notation. Failure scenario: an ingestion job writes `' 4.99'`; the next homepage render 500s instead of showing "brak danych".
- **Fix**: Validate with a strict pattern (`/^[+-]?\d+(\.\d+)?$/`) instead of `is_numeric`, and add a `MoneyTest` case for `'1e3'` and `' 1.5'`.
- **Decision**: SKIPPED — unreachable from `decimal:2` columns today; revisit when F-03 ingestion starts feeding `Money` raw vision-API strings.

### F5 — Raw product slugs are printed into Polish user-facing copy

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/components/comparison-report.blade.php:50, :66 (introduced at resources/views/home.blade.php:47 in `c79da6f`)
- **Detail**: The no-data branch renders `Brakuje: {{ implode(', ', $verdict->missingProducts) }}` — a guest sees "Brakuje: mleko-32-1l, maslo-extra-200g". `BasketLine::name()` (app/Pricing/BasketLine.php:25-28) exists precisely to give the human product name with a slug fallback and is unused on this path. Still live at HEAD, and the later refactor propagated it into the logged-in basket page, where the same raw slugs now appear inside "Usuń poniższe produkty, żeby porównać resztę koszyka" next to a delete button (:48-61). CLAUDE.md requires user-facing strings to be Polish product copy, not internal identifiers. Minor extra: with an empty `networks` table the same line renders "Brakuje: ." — no crash, but a stray period.
- **Fix**: Resolve each slug through the matching `BasketLine::name()` before rendering, in both the list and the sentence branch.
- **Decision**: SKIPPED

### F6 — `tests/Feature/ExampleTest.php` deleted against an explicit "leave it alone"

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/ExampleTest.php (deleted in `c79da6f`)
- **Detail**: Phase 2 §4 of the plan states "The existing `tests/Feature/ExampleTest.php` asserts `/` returns 200 and continues to pass; leave it alone." It was deleted instead. The deletion is forced, not careless: the file was the stock Laravel test with `RefreshDatabase` commented out, and once `/` began hitting `Network::query()->get()` against phpunit.xml's schemaless in-memory SQLite it could not have continued to pass. The commit message states the reason. The plan sentence was simply written on a stale assumption. Coverage is replaced by `HomePageTest::test_it_renders_the_verdict_and_both_chain_totals()`. What is genuinely lost is the only assertion that `/` returns 200 against an *unseeded* database — the no-data test at HomePageTest.php:74 seeds and then expires leaflets rather than seeding nothing. That is the same untested path as F3(a).
- **Fix**: Add a `HomePageTest` case that hits `/` with no seeded data at all and asserts 200 plus "Brak danych" — closing both the plan-adherence gap and F3's untested branch in one test.
- **Decision**: FIXED — `HomePageTest::test_the_page_survives_an_empty_database` added; asserts 200, "Brak danych" and no winner named against a completely empty database.

### F7 — A duplicated slug in the basket silently under-counts the total

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/BasketComparator.php:100 (with :103-108, :185-192)
- **Detail**: `NetworkResult::$lines` is keyed by product slug (`$priced[$line->slug] = $linePrice`), but `$basket` is a positional list. Two entries for the same slug silently overwrite, so `sum($priced)` counts that product once while `ComparisonReport::$basketLines` still carries both — the page would print two milk rows and a chain total provably lower than the rows above it. Likelihood is low and falling: the config basket is hand-authored and correct, and the later S-02 slice keys its session basket by slug (`app/Basket/BasketSession.php:59-65`, "Add to an existing line rather than creating a second one"), so the user-facing path cannot produce a duplicate. Recorded because the comparator's own contract accepts a positional list and does not defend itself.
- **Fix**: Collapse duplicates by summing quantities in `resolveBasketLines()` before pricing, and add an edge-case test.
- **Decision**: SKIPPED

### F8 — `networkProducts.network` is eager-loaded but never read

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/BasketComparator.php:63
- **Detail**: `priceLine()` matches listings on `firstWhere('network_id', $network->id)` (:123-124) and the view reads `$result->network` — the `Network` model loaded separately at :35 — so no code path touches `NetworkProduct::network()`. The relation costs one extra query plus hydration on every comparison. The query-count bound in `BasketComparatorEdgeCasesTest.php:251` allows ≤ 8 and would still pass with it removed.
- **Fix**: Delete the `'networkProducts.network'` entry from the `with()` call.
- **Decision**: SKIPPED

### F9 — Dead code: `VerdictType::label()` and `Money::isZero()`

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Pricing/VerdictType.php:14-21, app/Pricing/Money.php:75-78
- **Detail**: `VerdictType::label()` has zero callers — the enum is referenced only for its cases — while the view hardcodes the same three Polish strings, which already disagree with it in case ("Taniej w" vs `'taniej w'`). Two sources of truth for one user-facing string, one of them unexercised. Contrast `Scenario::label()` (Scenario.php:21-27), which the view does use and which follows the `PromoType::label()` precedent correctly. `Money::isZero()` likewise has no call sites in `app/`, `resources/` or `tests/`.
- **Fix**: Use `VerdictType::label()` in the report component (or delete it), and delete `Money::isZero()`.
- **Decision**: SKIPPED

### F10 — Per-line breakdown is driven by the without-card scenario only

- **Severity**: 📝 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: resources/views/home.blade.php:80 at `c79da6f`; now resources/views/components/comparison-report.blade.php
- **Detail**: The breakdown iterates `$report->withoutCard->results` and appends a "Z kartą: …" line where the card total differs. A listing priced *only* by a `loyalty_card` entry — no `none`/`simple` row on that listing — therefore renders the per-line "brak danych" state even though the with-card scenario prices it fine. The whole-basket verdicts stay correct (each scenario is computed independently in `compareScenario`), so this is a display edge, not a wrong number, and it cannot arise from the current seed. It matters more now than at merge time: the same template is the shared component for the logged-in basket page, and F-03 ingestion will eventually produce card-only listings.
- **Fix A ⭐ Recommended**: Drive the breakdown from the scenario the displayed verdict belongs to, rendering the per-line list once per shown scenario when the card changes the outcome.
  - Strength: The lines under a verdict always add up to that verdict's total, which is the whole point of showing them.
  - Tradeoff: Duplicates the line list on the two-scenario path — more vertical scrolling on a phone, which Phase 2 deliberately fought.
  - Confidence: MEDIUM — correct in principle; the mobile layout cost is real and only judgeable on screen.
  - Blind spot: Not verified how tall the doubled list actually gets at 375 px.
- **Fix B**: Keep the single list but fall back to the with-card `LinePrice` when the without-card scenario cannot price that line, labelling it as card-only.
  - Strength: Preserves the compact one-list layout and removes the false per-line "brak danych".
  - Tradeoff: A single list then mixes lines from two scenarios, so the column no longer sums to any one displayed total.
  - Confidence: MEDIUM — cheaper to build, weaker guarantee.
  - Blind spot: Whether a mixed list confuses the reader more than the duplication does.
- **Decision**: FIXED via Fix A — the breakdown in `resources/views/components/comparison-report.blade.php` now loops the same `$scenarios` list the verdict section uses, labelling each list when two are shown; the "Z kartą: …" per-line annotation is kept only in the single-list view, where it still carries information. Verified on the running app: the seeded basket renders two labelled lists (8 product headings for 4 lines), and `HomePageTest::test_it_shows_both_card_scenarios_when_the_card_changes_the_outcome` covers the path.

## Notes (not findings)

- **Timezone, already remediated.** At `c79da6f` the app ran in UTC (`config/app.php`) while leaflet windows are Polish calendar dates, so `today()` in `compare()` resolved to the previous calendar day between 00:00 and 02:00 Warsaw time — an expired leaflet could price the basket for two hours a day, and a fresh one could falsely read "brak danych". Fixed by `fbef5d7` (`Europe/Warsaw`), which landed after this change. Recorded only because the engine's freshness guarantee, which its docblock calls "structural", was at merge time dependent on a config value owned by a different slice.
- **No security findings.** The homepage is public; every DB-sourced string (product name, listing name, brand, size label, network name, promo label, missing-product slugs) goes through `{{ }}`, and there is no `{!! !!}` in any Blade file. No raw SQL, no request input reaches the query builder on this path — the basket comes from config. `routes/web.php` at `c79da6f` holds exactly `GET /` and the pre-existing `GET /_version`, which reads a fixed `base_path('REVISION')` behind `abort_unless(file_exists(...), 404)`.
- **Money math is sound.** Every amount enters through a string-only constructor, every operation is `bc*` at explicit scale 2, comparison is `bccomp` not `<`/`==`, `times()` takes only `int`, and `ddev exec php -i` confirms the container really runs `bcmath.scale => 0` — so `MoneyTest`'s `bcscale(0)` pin reproduces production rather than an ambient default, and its meta-test at MoneyTest.php:36-40 fails loudly if that pin ever stops working. The group formula was hand-traced and is exact at scale 2 with no intermediate division. `grep` for `round(`, `floatval`, `(float)`, `number_format` across `app/` returns nothing.
- **Extras are justified, not scope creep.** `app/Pricing/BasketLine.php` and `app/Pricing/LineCost.php` were not named in the plan's file list but are structural necessities of contracts the plan did specify: `LineCost` carries the `(Money, PromoType, bool needs-more-items)` triple the plan's own calculator contract describes, and `BasketLine`'s nullable `product` is what makes "a missing slug produces NoData, not an exception" expressible. Nothing from the "What We're NOT Doing" list appeared.
- **Architecture holds.** The domain layer carries live Eloquent models into the view, and nothing structurally prevents a future template from writing `$price->listing->network->name` inside a loop and reintroducing an N+1 — the query-count test guards the engine, not the views. Defensible for a solo MVP, and the `compare(array $basket, $date)` signature proved reusable: the later `BasketController` consumes it unchanged. The next view-heavy slice should get its own query-count assertion.
