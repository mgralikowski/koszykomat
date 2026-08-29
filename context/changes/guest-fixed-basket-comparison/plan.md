# Guest Fixed Basket Comparison Implementation Plan

## Overview

Build the promo rule engine — the product's wedge — and put its output on the homepage: a fixed example-basket comparison of Lidl vs Biedronka with a "gdzie taniej" verdict, all four promo mechanics priced honestly, the matched product pairs shown explicitly, and each price carrying its leaflet validity window.

This is roadmap item **S-01**, the north star (`context/foundation/roadmap.md`, "Slices"). Its only prerequisite, F-01, landed in `ee1957b`. It carries the four mandatory PHPUnit promo tests required by `CLAUDE.md`.

## Current State Analysis

The data layer exists and is seeded; everything above it is greenfield.

**What exists (from F-01, commits `200bec5` / `80e24fd`):**

- `app/Enums/PromoType.php` — `none` / `simple` / `one_plus_one` / `second_for_fixed` / `loyalty_card`, with `requiredParameters()`, `forbiddenParameters()`, `isConditional()`, `parameterColumns()` and a Polish `label()`.
- Models `Network`, `Product`, `NetworkProduct`, `Leaflet`, `PriceEntry` with relations and casts. `PriceEntry` casts `regular_price` / `promo_price` / `second_item_price` as `decimal:2` (returns **strings**) and `required_quantity` as `integer`.
- Freshness scopes: `Leaflet::validOn($date)` and `PriceEntry::validOn($date)` (`app/Models/Leaflet.php:63`, `app/Models/PriceEntry.php:82`), both defaulting to today and both accepting an injectable date.
- `config/koszykomat.php` — the example basket as `[['product' => slug, 'quantity' => int], …]`, 4 lines with quantities 2 / 1 / 1 / 4.
- `ExampleBasketSeeder` — 2 networks, 4 products listed in both, 1 current leaflet each, 9 price entries covering all five promo types.
- Factories for all five models, with `simple()` / `onePlusOne()` / `secondForFixed()` / `loyaltyCard()` states on `PriceEntryFactory` that set exactly the parameters each mechanic requires.

**What's missing:**

- No pricing logic of any kind. `app/` contains only `Enums/`, `Http/Controllers/` (base `Controller` only), `Models/`, `Providers/`.
- No layout, no components, no application views. `resources/views/welcome.blade.php` is the stock 223-line Laravel splash page and the only view in the project.
- `routes/web.php` still returns `view('welcome')` from a closure on `/`.

**Key constraints discovered:**

- **`decimal:2` returns strings.** Verified by round-trip in F-01: `regular_price` reads back as `'3.49'`. Any raw `+` / `*` silently coerces to float.
- **BCMath is available in both ddev and production**, confirmed by the developer on the DirectAdmin VPS. `ddev exec php -m` lists `bcmath`, `intl`, `mbstring` on PHP 8.5.3. **`bcmath.scale` is `0`** — the PHP default — which makes every `bc*` call that omits an explicit scale truncate to a whole number (see Critical Implementation Details).
- **`APP_LOCALE=en`** while all user-facing strings must be Polish. No localization infrastructure exists and none is in scope; Polish strings are written directly in Blade, per `CLAUDE.md`.
- **Tests run on in-memory SQLite** (`phpunit.xml:26-27`); `ddev composer test` is the gate. Current suite: 15 tests, 79 assertions.
- `context/foundation/lessons.md` does not exist yet — no recorded team rules to honor.

### Key Discoveries

- The seeded data already contains the hard case: Biedronka's masło has both a `none` (8,49) and a `loyalty_card` (6,49) entry in the same leaflet, and it **flips that line** — Lidl wins it without the card (7,99), Biedronka with it. The two-scenario design is exercised by the default seed, not just by tests.
- The seeded basket totals to Lidl 62,43 vs Biedronka 67,46 with the card (69,46 without), split two lines each — so the homepage will show a genuinely computed verdict, not a trivial one.
- The seeded conditional lines both use even quantities (czekolada ×4), so **odd-quantity behavior is untested by the seed** and must be covered by purpose-built test fixtures.
- `PriceEntryFactory`'s per-mechanic states make each mandatory promo test a one-liner to set up, and structurally prevent a test from building a row that violates the promo-parameter contract.

## Desired End State

Visiting `https://koszykomat.ddev.site/` as a guest on a phone-width viewport shows, in Polish:

- A verdict banner naming the cheaper chain and the difference — or "brak danych" when any line can't be priced in either chain.
- Both scenarios when the loyalty card changes the outcome: a with-card and a without-card total, each with its own winner.
- A per-line breakdown: the canonical product, both chains' listings with brand and gramatura (so the user can judge the pairing), the price each chain charges for the requested quantity, which mechanic was applied (using `PromoType::label()`), and the leaflet's from–to window.
- A note on any line where a promo needed more items than the basket asks for.

Verification: `ddev composer test` passes including four mechanic-specific tests that each assert a computed basket total, and a feature test asserting the page renders the verdict and totals. `ddev composer lint` passes.

## What We're NOT Doing

- **No basket builder, no persistence, no auth.** The basket is the fixed config fixture. Building your own basket is S-02; saving it is S-03; login is F-02.
- **No "full report" page.** The homepage is the whole deliverable. The login gate that Access Control describes is "build your own basket", not "see more detail" — that gate ships with S-02.
- **No leaflet ingestion.** The engine reads whatever is in the tables; F-03 fills them from real leaflets.
- **No automated product matching.** Pairing is F-01's canonical-product foreign key; no fuzzy matching, no weight normalization, no substitutes.
- **No reusable Blade component library.** A layout plus one page, styled with Tailwind utilities directly. S-02 can extract components once a second consumer exists.
- **No localization infrastructure.** Polish strings live in the Blade templates; no `lang/` files, no locale switching.
- **No caching, no queues, no progress UI.** The comparison is a handful of rows behind one eager-loaded query; the NFR's "visible progress for long processing" branch does not apply.
- **No admin or data-correction UI.**

## Implementation Approach

The engine is a small set of readonly value objects plus two services, kept entirely free of Eloquent-mutating logic so it stays testable and reusable by S-02.

**Money wraps BCMath at scale 2.** `decimal:2` hands back strings like `'3.49'`, and BCMath operates on exactly that decimal representation without ever going through a binary float, so the arithmetic is exact end to end. BCMath is confirmed present in both ddev and production, and it leaves the door open for mechanics that need division — percentage discounts, price-per-unit normalization — without changing the money representation later. This is what the F-01 docblocks already mandate; Phase 1 keeps that instruction and narrows it to `Money` as the one supported way to do it.

The catch is the `bcmath.scale = 0` default: with no explicit scale, `bcadd('3.49', '0.00')` returns `'3'`. Every call site must pass the scale argument, which is exactly why all `bc*` calls are confined inside `Money` — one file where that rule has to hold, rather than a rule scattered across the engine.

**One formula covers both conditional mechanics.** For a complete group the shopper pays the regular price once plus `second_item_price` for each additional item in the group; leftover items outside a complete group are charged at the regular price:

```
groups     = intdiv(quantity, required_quantity)
remainder  = quantity % required_quantity
cost       = groups × (regular + second_item_price × (required_quantity − 1))
           + remainder × regular
```

At `required_quantity = 2` this yields `3.49 + 0.00` per pair for 1+1 and `4.99 + 0.01` per pair for second-for-grosz, with any odd item at full price — the exact-quantity semantics chosen during planning. Nothing is ever charged for an item the basket didn't ask for.

**Two scenarios, computed in one pass.** The comparison runs twice over the same loaded data: once excluding `loyalty_card` entries, once including them. Each scenario independently picks, per listing, the cheapest valid entry at the requested quantity, and produces its own per-chain totals and verdict. The page shows one verdict when both scenarios agree and both when they differ.

**The no-data rule is whole-basket.** If any line lacks a priceable entry in either chain, the verdict for that scenario becomes "brak danych" — but every line that could be priced is still rendered, along with an explicit statement of what's missing. A verdict is never computed over two different baskets.

**Freshness is enforced at load time.** The comparator loads price entries through `PriceEntry::validOn($date)`, so expired rows never reach the calculator. This makes the guardrail structural: there is no code path where a stale price contributes to a total.

## Critical Implementation Details

**`bcmath.scale` is 0, so every `bc*` call must pass an explicit scale.** This is the single most dangerous fact in this plan. With the default scale, `bcadd('3.49', '0.00')` returns `'3'` and `bcmul('4.99', '2')` returns `'9'` — the fractional part is *truncated, not rounded*, silently, with no warning. A basket total computed that way looks like a plausible number and is wrong by złoty, not by grosz. Pass the scale argument (2) to every `bcadd` / `bcsub` / `bcmul` / `bccomp` call. Do **not** rely on a global `bcscale(2)` in a service provider: it is process-global mutable state that PHPUnit, `artisan tinker` and queue workers can each bootstrap differently, so a test suite passing proves nothing about the request path. `Money` must own every `bc*` call in the codebase, and its tests must assert that a fractional result survives (see the truncation test in Phase 1 §7).

**Never let a price touch a float.** BCMath keeps the values as decimal strings; the risk is code outside `Money` doing `(float) $entry->regular_price` or `$a + $b` on cast values. `Money` accepts and returns strings, and nothing in the engine should ever hold a price as `float` or `int`.

**Guard against a zero or null `required_quantity`.** `intdiv($qty, 0)` throws `DivisionByZeroError`. The promo-parameter contract makes `required_quantity` non-null for conditional mechanics, but the column is nullable at the database level and ingestion (F-03) will eventually write it — the calculator must treat a conditional entry with a missing or `< 2` quantity as unpriceable rather than crashing the homepage.

**Eager-load in one pass.** The comparator must load products with `networkProducts.network` and `networkProducts.priceEntries.leaflet` constrained by `validOn($date)` in a single `with()` call. Iterating listings and lazily touching `->priceEntries` would issue a query per line — the N+1 that `CLAUDE.md` forbids and that the <2 s NFR depends on avoiding.

**The date must be injectable end to end.** `BasketComparator` takes the comparison date as a parameter defaulting to today, and threads it into `validOn()`. Without this, no test can assert the expired-data path without manipulating the system clock.

## Phase 1: Pricing engine + mandatory promo tests

### Overview

Build the whole domain layer — money, per-line promo pricing, scenarios, verdict, and the basket comparator — with no UI. Cover it with the four mandatory promo tests plus the edge cases the seed doesn't reach.

### Changes Required

#### 1. Money value object

**File**: `app/Pricing/Money.php` (new; `app/Pricing/` does not exist)

**Intent**: Exact money arithmetic with no float anywhere, and Polish formatting for the view. The engine's foundation — everything else operates on this type rather than on raw strings.

**Contract**: A readonly value object holding the amount as a normalized decimal string at scale 2, and the **only** place in the codebase that calls a `bc*` function. Construction from a `decimal:2` string (`fromDecimalString`, accepting anything Eloquent's cast returns) and a `zero()` constructor; `plus(Money)`, `minus(Money)`, `times(int)` for quantity multiplication, comparison helpers (`isLessThan`, `equals`), an accessor returning the `decimal:2` string, and `format()` returning Polish currency text (comma decimal separator, `zł` suffix — e.g. `62,43 zł`).

Define the scale as a class constant (`SCALE = 2`) and pass it explicitly to every `bcadd` / `bcsub` / `bcmul` / `bccomp` call. Normalize on construction so every instance holds exactly two decimal places, which keeps `equals()` and string comparison honest (`'3.5'` and `'3.50'` must be the same money). Reject a non-numeric input with an exception rather than letting it become `'0'`.

Take no `float` or `int` amount in the constructor — accepting a float is the door through which drift re-enters, and `times(int)` covers the only multiplication the engine performs.

#### 2. Promo calculator

**File**: `app/Pricing/PromoCalculator.php` (new)

**Intent**: Given one price entry and a quantity, compute what that quantity actually costs under that entry's mechanic — the single place the four FR-007 mechanics are encoded.

**Contract**: A method taking a `PriceEntry` and an integer quantity, returning the total `Money` for that line (or a null/failure signal when the entry is unpriceable). Behavior per `PromoType`:

- `None` — `quantity × regular_price`.
- `Simple`, `LoyaltyCard` — `quantity × promo_price`.
- `OnePlusOne`, `SecondForFixed` — the group formula from Implementation Approach.

It must also report **why** a line costs what it does, so the view can explain the number: the mechanic applied, and a flag for the case where a conditional promo did not apply at all because `quantity < required_quantity` (the basket asks for 1 against a 1+1 offer). Return an unpriceable signal — never an exception, never a wrong number — when a conditional entry has a null or `< 2` `required_quantity` (see Critical Implementation Details).

#### 3. Result value objects

**File**: `app/Pricing/LinePrice.php`, `app/Pricing/NetworkResult.php`, `app/Pricing/Verdict.php`, `app/Pricing/VerdictType.php`, `app/Pricing/ScenarioComparison.php`, `app/Pricing/ComparisonReport.php` (all new)

**Intent**: A typed result the view can render without recomputing anything or reaching back into Eloquent. S-02 will consume the same shapes for its report.

**Contract**:

- `LinePrice` — one chain's price for one basket line: the `NetworkProduct` (carrying brand and size label for the FR-008 pairing display), the quantity, the winning `PriceEntry`, the applied `PromoType`, the total `Money`, the leaflet's `valid_from`/`valid_to`, and the "promo needed more items" flag.
- `NetworkResult` — one chain within one scenario: its `LinePrice`s keyed by product slug, the slugs it could not price, and the summed total (null when anything is unpriceable).
- `VerdictType` — a string-backed enum: `Winner`, `Tie`, `NoData`. Give it a Polish `label()`, consistent with how `PromoType` handles user-facing text.
- `Verdict` — the type plus, for `Winner`, the winning network and the `Money` margin; for `NoData`, the product slugs responsible.
- `ScenarioComparison` — one scenario (with or without card): its per-network `NetworkResult`s and its `Verdict`.
- `ComparisonReport` — the two `ScenarioComparison`s plus a derived flag for whether the card changes the outcome (different winner **or** different margin), which is what tells the view to show one verdict or two.

Represent the two scenarios with a small string-backed enum (`app/Pricing/Scenario.php`) rather than booleans, so the view and tests read clearly and a third pricing mode later doesn't mean rewriting signatures.

#### 4. Basket comparator

**File**: `app/Pricing/BasketComparator.php` (new)

**Intent**: The orchestrator — load the basket's products with everything needed in one query, price every line for every chain in both scenarios, apply the no-data rule, and return a `ComparisonReport`.

**Contract**: A method taking the basket (the `config('koszykomat.example_basket')` shape: a list of `product` slug + `quantity`) and an optional comparison date defaulting to today, returning a `ComparisonReport`.

Responsibilities, in order:

1. Load `Product`s whose slug is in the basket, eager-loading `networkProducts.network` and `networkProducts.priceEntries.leaflet` with the entries constrained by `validOn($date)` — one query pass, no lazy loads (see Critical Implementation Details).
2. Load the chains from `networks` rather than hardcoding Lidl and Biedronka, so the architecture stays chain-agnostic per the PRD.
3. For each scenario, for each line, for each chain: filter the listing's valid entries to those allowed in the scenario (`loyalty_card` excluded without the card), price each through `PromoCalculator`, and select the cheapest result. On a tie in cost, prefer the entry with the simpler mechanic so the explanation shown to the user is the plainest true one.
4. A line is unpriceable for a chain when the product has no listing there, the listing has no valid entry, or every candidate entry came back unpriceable.
5. Build the `Verdict`: `NoData` if any line is unpriceable in **any** chain; otherwise `Winner` with the margin, or `Tie` on exact equality.

A missing product slug in the config (present in the basket, absent from the database) is an unpriceable line in every chain — it must produce `NoData`, not an exception, since that is exactly the guardrail's purpose.

#### 5. Declare the BCMath dependency and narrow the F-01 arithmetic docblocks

**File**: `composer.json`, `app/Enums/PromoType.php`, `app/Models/PriceEntry.php`

**Intent (composer.json)**: The engine's correctness now depends on an extension that is present today but is not declared anywhere. Add `"ext-bcmath": "*"` to `require` so a server without it fails at `composer install` — loudly, at deploy time — instead of producing wrong prices at runtime.

**Contract (composer.json)**: One entry in `require` alongside `php` and the framework. No version constraint; the extension has no meaningful versioning. `ddev composer check-platform-reqs` must pass afterwards.

**Intent**: Both carry a class docblock telling consumers to compute with BCMath or integer grosze. That instruction is right in spirit but now under-specified: it permits a raw `bcadd` without a scale, which the `bcmath.scale = 0` default turns into silent truncation.

**Contract**: Narrow the arithmetic paragraph in each to name `App\Pricing\Money` as the required way to do money arithmetic, and state that direct `bc*` calls outside `Money` are not permitted because of the zero default scale. Keep the existing warning that `decimal:2` returns coercible strings. Do not change any behavior.

#### 6. The four mandatory promo tests

**File**: `tests/Feature/Pricing/BasketComparatorTest.php` (new; `tests/Feature/Pricing/` does not exist)

**Intent**: Satisfy `CLAUDE.md`'s hard requirement — each of the four mechanics gets a PHPUnit test asserting a **computed basket total**. These are the product's credibility tests.

**Contract**: `RefreshDatabase`, building purpose-built fixtures with the F-01 factories (not the example seeder, so each test's numbers are self-evident and independent of seed changes). One test per mechanic, each constructing a one-line basket with a known quantity and asserting the exact `NetworkResult` total:

- **Simple promo price** — quantity × promo price.
- **1+1 gratis** — an even quantity paying for half.
- **Second for 1 grosz** — a pair costing `regular + 0.01`.
- **Loyalty-card price** — the with-card scenario uses the card price while the without-card scenario uses the regular one, from the same fixture.

Assert on the `decimal:2` string, never on a float.

#### 7. Engine edge-case tests

**File**: `tests/Unit/Pricing/MoneyTest.php` (new), `tests/Feature/Pricing/BasketComparatorEdgeCasesTest.php` (new)

**Intent**: Cover the paths the seed and the four mandatory tests don't reach — the ones where a wrong answer would be silent rather than obvious.

**Contract**:

`MoneyTest` (pure unit, no database):

- **The truncation guard** — the test this whole class exists for: adding `'3.49'` and `'0.00'` yields `'3.49'`, not `'3'`, and multiplying `'4.99'` by 2 yields `'9.98'`, not `'9'`. Run it with `bcscale(0)` deliberately set beforehand so it reproduces the production `bcmath.scale = 0` configuration rather than whatever a given environment happens to bootstrap. Without this, the suite could pass on a machine with a non-zero default scale and the verdict would still be wrong in production.
- Normalization: `'3.5'` and `'3.50'` compare equal and both render as `3,50 zł`.
- Exactness across a long chain of additions (sum 0.01 a hundred times → `'1.00'`).
- `format()` produces Polish output with a comma decimal separator.
- Negative values round-trip through `minus()`.
- A non-numeric input throws rather than silently becoming zero.

`BasketComparatorEdgeCasesTest` (`RefreshDatabase`, factories):

- **Odd quantity under a conditional mechanic** — 3 items under 1+1 charges two units, not one and not two pairs; 3 items under second-for-grosz charges `regular + 0.01 + regular`.
- **Quantity below `required_quantity`** — one item against a 1+1 offer costs the regular price and the line is flagged as "promo needed more items".
- **Cheapest-wins** — a listing carrying two valid entries is priced by the cheaper one at the basket's quantity, and the reported mechanic is the one that won.
- **Card scenarios** — with-card and without-card totals differ for a listing that has both entries, and `ComparisonReport` reports that the card changes the outcome.
- **Expired data** — a basket whose only entries sit in an expired leaflet yields `NoData`, and passing a date inside the window yields a winner from the same fixture.
- **Missing listing** — a product listed in only one chain yields `NoData` while the priced line still appears in the result.
- **Malformed conditional entry** — a `one_plus_one` row with a null `required_quantity` makes the line unpriceable rather than throwing.

### Success Criteria

#### Automated Verification

- Full test suite passes: `ddev composer test`
- The four mandatory mechanic tests exist and pass: `ddev artisan test tests/Feature/Pricing/BasketComparatorTest.php`
- Edge cases pass: `ddev artisan test tests/Feature/Pricing/BasketComparatorEdgeCasesTest.php tests/Unit/Pricing/MoneyTest.php`
- Code style passes: `ddev composer lint`
- The comparator issues no N+1: assert a bounded query count (`DB::enableQueryLog()` or `assertQueryCount`-style check) inside the edge-case test for a multi-line basket
- BCMath is declared as a platform requirement: `ddev composer check-platform-reqs` passes and `composer.json` lists `ext-bcmath`
- No `bc*` call exists outside `Money`: `grep -rn "bcadd\|bcsub\|bcmul\|bcdiv\|bccomp\|bcscale" app/` returns hits only in `app/Pricing/Money.php`

#### Manual Verification

- Hand-check the engine against the seeded basket in tinker: totals match the plan's figures (Lidl 62,43 / Biedronka 67,46 with card, 69,46 without) and the winner is Lidl in both scenarios
- Confirm the masło line is won by Biedronka with the card and by Lidl without it — the card genuinely flips a line
- Read the four mandatory tests and confirm each asserts a real computed total for its mechanic, not a tautology

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Guest homepage

### Overview

Put the engine's output on screen: a mobile-first Polish page showing the verdict and the full per-line breakdown, replacing the stock Laravel welcome page.

### Changes Required

#### 1. Home controller

**File**: `app/Http/Controllers/HomeController.php` (new), `routes/web.php`

**Intent**: Resolve the fixed basket from config, run the comparator, and hand a `ComparisonReport` to the view. The controller stays thin — no pricing logic, no formatting.

**Contract**: A single-action (`__invoke`) controller resolving `BasketComparator` from the container and passing `config('koszykomat.example_basket')`. `routes/web.php` replaces the `/` closure with this controller, keeping the existing `/_version` route untouched. The route stays public — no middleware.

#### 2. Application layout

**File**: `resources/views/layouts/app.blade.php` (new)

**Intent**: The first real layout: document shell, Polish `lang`, viewport meta, Vite assets, and the fonts directive. Everything after this slice extends it.

**Contract**: `lang="pl"`, `<meta name="viewport" content="width=device-width, initial-scale=1">`, `@fonts` and `@vite(['resources/css/app.css', 'resources/js/app.js'])` as in the current `welcome.blade.php` head, a `@yield`/slot for page content, and a page `<title>` built from `config('app.name')` (already `Koszykomat`).

Do not carry over `welcome.blade.php`'s inline-`<style>` fallback branch — that exists only so the stock page renders before a build, and it would mask a genuinely missing Vite build.

#### 3. Homepage view

**File**: `resources/views/home.blade.php` (new), deleting `resources/views/welcome.blade.php`

**Intent**: The product's shop window — show the verdict and prove it, so a guest can see why the number is trustworthy without logging in.

**Contract**: Extends the layout; all copy in Polish. Sections, in order:

1. **Verdict banner** — the winning chain and the margin (`Money::format()`), or "brak danych" with the products responsible. When `ComparisonReport` says the card changes the outcome, show both scenarios (with-card and without-card) as two clearly-labelled results rather than one headline; when it doesn't, show a single verdict.
2. **Per-chain totals** — the two basket totals side by side, so the margin is visibly derived rather than asserted.
3. **Per-line breakdown** — one block per basket line: the canonical product name and requested quantity; then, per chain, that chain's listing name with **brand and gramatura** (FR-008's explicit pairing — the user must be able to see that Pilos 1 l was compared with Łowicz 1 l, and 1 kg with 900 g), the line total, the applied mechanic via `PromoType::label()`, and the leaflet's from–to window. Flag lines where the promo needed more items, and render an explicit "brak danych" state for a line a chain can't price.
4. **Freshness note** — state that prices come from the current leaflets and show the window, per the data-freshness NFR.

Mobile-first: single-column stacked layout at phone width, the two chains side by side only from a `sm:`/`md:` breakpoint up. Tailwind utilities directly — no component extraction (out of scope). Dates formatted as Polish `d.m.Y`.

#### 4. Homepage feature test

**File**: `tests/Feature/HomePageTest.php` (new)

**Intent**: Prove the wiring — that the number on the page is the number the engine computed, which the unit-level promo tests deliberately don't cover.

**Contract**: `RefreshDatabase` plus the `ExampleBasketSeeder`, asserting: `/` returns 200; the page contains both chains' names and both formatted totals; the verdict names the expected winner; at least one promo mechanic label and one validity window appear; and the brand/gramatura of a matched pair is rendered. Add a case seeding no data at all (or only expired leaflets) and assert the page shows "brak danych" and does **not** name a winner — the guardrail, asserted at the UI level.

The existing `tests/Feature/ExampleTest.php` asserts `/` returns 200 and continues to pass; leave it alone.

### Success Criteria

#### Automated Verification

- Full test suite passes: `ddev composer test`
- Homepage test passes: `ddev artisan test tests/Feature/HomePageTest.php`
- Frontend builds: `ddev npm run build`
- Code style passes: `ddev composer lint`
- `resources/views/welcome.blade.php` no longer exists and nothing references it: `grep -r "welcome" resources/ routes/ app/`

#### Manual Verification

- Load `https://koszykomat.ddev.site/` at phone width (375 px) and confirm the whole comparison is readable and usable single-column, with no horizontal scroll
- Confirm the verdict, both totals, every line's mechanic label, matched brands/gramatura and validity windows render, with Polish characters intact
- Confirm the two card scenarios are distinguishable and it's clear which applies to whom
- Temporarily expire the seeded leaflets (or seed an incomplete basket) and confirm the page says "brak danych" and names no winner
- Confirm the page feels well within the <2 s NFR

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before the change is considered done.

---

## Testing Strategy

### Unit Tests

- `tests/Unit/Pricing/MoneyTest.php` — the `bcscale(0)` truncation guard, scale normalization (`'3.5'` equals `'3.50'`), exactness across a long addition chain, Polish formatting, negative values, and rejection of non-numeric input.

### Integration Tests

- `tests/Feature/Pricing/BasketComparatorTest.php` — **the four mandatory promo tests** from `CLAUDE.md`, each asserting a computed basket total for one mechanic.
- `tests/Feature/Pricing/BasketComparatorEdgeCasesTest.php` — odd quantities, quantity below `required_quantity`, cheapest-wins, card scenarios, expired data, missing listing, malformed conditional entry, and the query-count bound.
- `tests/Feature/HomePageTest.php` — rendered verdict and totals, pairing display, and the "brak danych" guardrail at the UI level.

### Manual Testing Steps

1. `ddev artisan migrate:fresh --seed && ddev npm run build`
2. Open `https://koszykomat.ddev.site/` and check the verdict against the plan's figures (Lidl 62,43 vs Biedronka 67,46 with card)
3. Narrow the viewport to 375 px and confirm the single-column layout holds with no horizontal scroll
4. Check every line shows both listings with brand and gramatura, the mechanic label, and the validity window
5. Set a seeded leaflet's `valid_to` into the past and reload — the page must say "brak danych" and name no winner
6. Restore the leaflet and confirm the verdict returns

## Performance Considerations

The <2 s NFR is met by construction: one eager-loaded query pass over four products, two chains and nine price entries, with all arithmetic in memory on integers. The risk is not volume but accidental laziness — touching `->priceEntries` outside the eager load would issue a query per line, which is why Phase 1 asserts a bounded query count rather than trusting review. No caching is introduced; at this data size it would add invalidation risk (stale prices are the one thing the guardrail exists to prevent) for no measurable gain.

## Migration Notes

No schema changes and no data migration — this slice is pure read. The only destructive act is deleting `resources/views/welcome.blade.php`, which nothing but the `/` route references. Rollback is reverting the commits; the F-01 data layer is untouched apart from two docblock edits.

## References

- Roadmap item: `context/foundation/roadmap.md` — S-01 "Guest fixed example-basket comparison (north star)"
- PRD: `context/foundation/prd.md` — FR-001, FR-007, FR-008, US-01, Business Logic, NFR (mobile-first, <2 s, data-freshness transparency), Access Control
- Prior change (data layer): `context/changes/price-promo-data-model-seed/plan.md`, commits `200bec5`, `80e24fd`, `ee1957b`
- Promo mechanics contract: `app/Enums/PromoType.php:47`
- Freshness scopes: `app/Models/Leaflet.php:63`, `app/Models/PriceEntry.php:82`
- Factory mechanic states: `database/factories/PriceEntryFactory.php:40`
- Example basket fixture: `config/koszykomat.php`
- Test conventions: `tests/Feature/Database/PricePromoSeedTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Pricing engine + mandatory promo tests

#### Automated

- [x] 1.1 Full test suite passes: `ddev composer test` — 98b1e34
- [x] 1.2 Four mandatory mechanic tests pass: `ddev artisan test tests/Feature/Pricing/BasketComparatorTest.php` — 98b1e34
- [x] 1.3 Edge-case and Money tests pass — 98b1e34
- [x] 1.4 Code style passes: `ddev composer lint` — 98b1e34
- [x] 1.5 Comparator issues no N+1 (bounded query count asserted in test) — 98b1e34
- [x] 1.6 BCMath declared as a platform requirement (`check-platform-reqs` passes, `ext-bcmath` in composer.json) — 98b1e34
- [x] 1.7 No `bc*` call outside `app/Pricing/Money.php` — 98b1e34

#### Manual

- [x] 1.8 Engine hand-checked in tinker against the seeded basket (62,43 / 67,46 / 69,46) — 98b1e34
- [x] 1.9 Masło line confirmed to flip between card scenarios — 98b1e34
- [x] 1.10 The four mandatory tests reviewed — each asserts a real computed total — 98b1e34

### Phase 2: Guest homepage

#### Automated

- [x] 2.1 Full test suite passes: `ddev composer test`
- [x] 2.2 Homepage feature test passes: `ddev artisan test tests/Feature/HomePageTest.php`
- [x] 2.3 Frontend builds: `ddev npm run build`
- [x] 2.4 Code style passes: `ddev composer lint`
- [x] 2.5 `welcome.blade.php` removed and unreferenced

#### Manual

- [x] 2.6 Page usable single-column at 375 px with no horizontal scroll
- [x] 2.7 Verdict, totals, mechanic labels, brand/gramatura pairs and validity windows all render in correct Polish
- [x] 2.8 Both card scenarios are distinguishable and clearly attributed
- [x] 2.9 Expired/incomplete data shows "brak danych" and names no winner
- [x] 2.10 Page feels well within the <2 s budget
