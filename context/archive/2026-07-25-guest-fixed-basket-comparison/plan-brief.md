# Guest Fixed Basket Comparison — Plan Brief

> Full plan: `context/changes/guest-fixed-basket-comparison/plan.md`
> Roadmap item: `context/foundation/roadmap.md` — S-01 (north star)

## What & Why

Build the promo rule engine — the product's wedge — and show its output on the homepage: a fixed example-basket comparison of Lidl vs Biedronka with a "gdzie taniej" verdict, all four promo mechanics priced honestly, matched product pairs shown explicitly, and every price carrying its leaflet validity window. This is the validation milestone: the smallest end-to-end flow that proves a promo-aware verdict is correct and worth trusting. It carries the four mandatory PHPUnit promo tests from `CLAUDE.md`.

## Starting Point

The F-01 data layer is done and seeded (`200bec5` / `80e24fd` / `ee1957b`): five models with promo-typed price entries, `validOn()` freshness scopes, per-mechanic factory states, and a seeded four-line basket. Above it, nothing exists — no pricing logic at all, and `resources/views/welcome.blade.php` (the stock 223-line Laravel splash page) is the project's only view, still served by a closure on `/`.

## Desired End State

A guest on a phone sees a Polish page with a verdict naming the cheaper chain and the margin — or "brak danych" when any line can't be priced — plus both card scenarios when the loyalty card changes the outcome, and a line-by-line breakdown showing each chain's listing with brand and gramatura, the price for the requested quantity, which mechanic was applied, and the from–to leaflet window.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Overbuy semantics | Exact quantity; promo applies to complete groups, remainder at regular price | Never charges for items the shopper didn't ask for and never overstates savings; lines where a promo needed more items are flagged instead. |
| Loyalty card | Two totals — with card and without, shown side by side when they differ | Honest for both kinds of shopper and answers the PRD's "karta rozdwaja werdykt" concern head-on. |
| Multiple valid offers | Cheapest total at the requested quantity wins, reporting which mechanic | Matches what the shopper actually pays and stays correct once ingestion produces overlapping offers. |
| Missing price data | Whole-basket "brak danych"; priced lines still shown | The strongest form of the guardrail — a winner is never named while comparing two different baskets. |
| Guest detail level | Full per-line breakdown for the fixed basket | The wedge is invisible unless promo pricing is on screen; the login gate becomes "build your own basket" (S-02). |
| UI investment | Purpose-built mobile-first page, Tailwind utilities, no component library | Mobile-first is an NFR and this is the product's only page; component extraction waits for a second consumer. |
| Test level | Four mechanic tests as engine tests + one page feature test | `CLAUDE.md` wants each mechanic asserting a computed basket total; at engine level a failure names the broken mechanic. |
| Money representation | BCMath at scale 2, wrapped in a `Money` value object | Confirmed available in ddev and production; operates on the `decimal:2` strings directly with no binary float, and leaves room for mechanics that need division later. |
| BCMath scale handling | Explicit scale on every call, all `bc*` confined to `Money` | `bcmath.scale = 0` means an omitted scale truncates silently (`bcadd('3.49','0.00')` → `'3'`); a global `bcscale(2)` is process state that tests and workers can bootstrap differently. |

## Scope

**In scope:** `Money` value object (BCMath, scale 2); `PromoCalculator` (all four mechanics + regular); result DTOs (`LinePrice`, `NetworkResult`, `Verdict`, `ScenarioComparison`, `ComparisonReport`); `BasketComparator` with two scenarios, cheapest-wins and the whole-basket no-data rule; `HomeController` + route; Blade layout + mobile-first Polish homepage; four mandatory promo tests, edge-case tests, `Money` unit tests, homepage feature test; removal of `welcome.blade.php`; `ext-bcmath` declared in `composer.json` and the two F-01 arithmetic docblocks narrowed to point at `Money`.

**Out of scope:** basket builder, persistence, auth (S-02/S-03/F-02); a separate "full report" page; leaflet ingestion (F-03); automated product matching; reusable Blade component library; localization infrastructure; caching, queues, progress UI; admin or data-correction UI.

## Architecture / Approach

Two services over readonly value objects, free of Eloquent-mutating logic so S-02 can reuse them. `BasketComparator` loads the config basket's products in one eager-loaded pass — listings, chains, and price entries already filtered through `validOn($date)`, so an expired price has no code path to a total. It then runs the same data twice, once excluding `loyalty_card` entries and once including them; each scenario picks the cheapest valid entry per listing via `PromoCalculator`, sums per chain, and produces its own verdict. One formula covers both conditional mechanics — `groups × (regular + second_item_price × (required_quantity − 1)) + remainder × regular` — which is 1+1 and second-for-grosz at `required_quantity = 2`. All arithmetic runs through `Money`, which wraps BCMath at scale 2 and is the only file in the codebase permitted to call a `bc*` function.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Pricing engine + mandatory promo tests | `Money`, `PromoCalculator`, result DTOs, `BasketComparator`; the four mandatory tests plus odd-quantity, cheapest-wins, card, expiry and malformed-entry cases | Getting a mechanic's semantics subtly wrong — the number looks plausible and the verdict is confidently false, which is the one failure the product cannot absorb |
| 2. Guest homepage | `HomeController`, layout, mobile-first Polish page with verdict, totals, per-line pairing and validity windows; page feature test | Presenting two card scenarios without confusing the reader, and keeping a dense four-line comparison legible in one column at 375 px |

**Prerequisites:** F-01 (done — `ee1957b`). A running ddev and a Vite build for phase 2.
**Estimated effort:** ~2 sessions across 2 phases; phase 1 is the larger and more delicate half.

## Open Risks & Assumptions

- **`bcmath.scale = 0` is the sharpest edge in this plan.** A `bc*` call without an explicit scale truncates silently — `bcadd('3.49','0.00')` returns `'3'` — so the total is wrong by złoty, not grosz, and looks entirely plausible. Mitigated by confining every `bc*` call to `Money`, passing the scale explicitly, and a unit test that pins `bcscale(0)` first so it reproduces the production configuration rather than the ambient one.
- **Float coercion remains the second path to drift.** `decimal:2` returns strings that coerce silently; any `(float)` cast or raw `+` on a price outside `Money` reintroduces it.
- **`required_quantity` is nullable in the database.** The promo-parameter contract says conditional mechanics have it, but nothing enforces that at the DDL level, so `intdiv($qty, 0)` is reachable once F-03 writes real data — the calculator must treat such rows as unpriceable rather than throw.
- **The seed doesn't exercise odd quantities.** Both seeded conditional lines use even quantities, so the odd-quantity path is proven only by purpose-built test fixtures, not by anything you'll see on screen.
- **"It depends" is a weaker headline.** Showing two card scenarios is the honest choice but a less punchy demo than a single winner; if the page reads as hedging, that's a presentation problem to solve in phase 2, not a reason to revisit the decision.
- Traffic scale (PRD Open Question 1) remains unanswered; it doesn't affect this slice, where the comparison is one query over nine rows.

## Success Criteria (Summary)

- A guest sees a correct, promo-aware verdict for the example basket on a phone, and can see the per-line evidence — mechanics, matched brands and gramatura, validity windows — behind the number.
- Incomplete or expired data produces "brak danych" and never a winner, asserted at both engine and UI level.
- Each of the four promo mechanics has a passing PHPUnit test asserting a computed basket total, satisfying the `CLAUDE.md` requirement.
