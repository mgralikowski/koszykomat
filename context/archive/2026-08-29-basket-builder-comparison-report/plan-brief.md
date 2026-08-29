# Basket Builder + Full Comparison Report — Plan Brief

> Full plan: `context/changes/basket-builder-comparison-report/plan.md`
> Roadmap item: `context/foundation/roadmap.md` — S-02

## What & Why

Let a logged-in user assemble their own basket and run the full Lidl vs Biedronka comparison on it. This is roadmap item **S-02**, the largest user-facing slice in the MVP and the one that turns the homepage demo into a product: the same engine, the same verdict, the same promo mechanics and matched-product evidence — but over a basket the user chose. It adds no pricing logic whatsoever; S-01 already built and tested that.

## Starting Point

Both prerequisites are implemented. S-01 left `BasketComparator::compare()` taking exactly the shape a user basket has, with the whole-basket "brak danych" guardrail and freshness filtering already inside it. S-01 also left the full report markup — verdict, per-chain totals, per-line brand/gramatura/mechanic/validity window — sitting in `home.blade.php`, where roughly 120 of its 147 lines are report, not homepage. F-02 left working Google login and a header whose own comment reserves the spot for the basket link, but its callback still hard-redirects to `/` and no `redirectGuestsTo` is configured, so an `auth`-gated route would currently throw. There is no basket schema, no JavaScript, and the catalogue is four seeded products.

## Desired End State

A logged-in user opens the basket page from the homepage call-to-action, picks products, sets quantities, and presses „Porównaj". Below the basket the full report appears — the same component the homepage renders. When a line cannot be priced in some chain, no winner is named: the report says „brak danych", names the products responsible, and offers to remove each one. Editing the basket hides the now-stale report and says so. A guest who clicks the call-to-action goes to Google and comes back to the basket page, not to `/`.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Basket state | Session only — no tables in S-02 | Keeps the schema footprint at zero and leaves the `baskets` model to S-03, whose stated outcome is saving baskets; F-02's brief warned that migrations go forward-only from S-03 onward. |
| Interaction model | Server-rendered forms, zero JS | Matches the project's actual state (`app.js` is a single `//`), adds no dependency, and makes the <2 s budget trivially safe. |
| Catalogue | Build on the four seeded products, no seed expansion | Honest to the data that exists; hand-seeding a bigger catalogue is throwaway work the day F-03 writes to the same schema. |
| Report markup | Extract to `<x-comparison-report>`, used by both pages | One source of truth for the product's most trust-sensitive render — a fix to a mechanic label or validity window cannot drift between demo and report. |
| Auth gate | Route behind `auth`; homepage CTA; return to the builder after login | Exactly the Access Control path in the PRD, and it finally gives the guest demo a conversion step. |
| Unpriceable product | Keep the whole-basket rule, name the culprit, offer removal | Preserves the guardrail untouched (the engine already returns the slugs) while replacing a dead end with a one-click action. |
| Page layout | One page, explicit „Porównaj" button | Mirrors US-01's "tworzy koszyk **i uruchamia porównanie**" and avoids recomputing on every tweak. |
| Test depth | Auth gate only; existing `HomePageTest` retained | `CLAUDE.md` makes MVP tests optional outside the promo mechanics; the gate is the one security-shaped guarantee, and `HomePageTest` already covers the extraction's regression risk without new code. |
| Edge cases | Empty state, duplicate merging, quantity clamp 1–99, per-line removal, clear basket | These are the states a user hits in the first minute, and an unclamped quantity flows straight into `Money::times()`. |

## Scope

**In scope:** `<x-comparison-report>` extracted from the homepage; `BasketSession` service over the session; `BasketController` with show/add/update/remove/clear/compare; `auth`-gated `/koszyk` routes; `redirectGuestsTo` + `redirect()->intended()` so login returns to the builder; mobile-first Polish builder view with picker, line list, quantity forms and empty state; homepage call-to-action; removable missing-product affordance in the no-data verdict; one auth-gate feature test; quantity bounds in `config/koszykomat.php`.

**Out of scope:** any migration or basket table; basket surviving logout or session expiry; JavaScript, Livewire, Alpine; seed expansion; search, autocomplete, filtering, pagination; any change under `app/Pricing/`; limit on distinct lines; pre-filtering products that lack prices; tests beyond the auth gate; saved-basket list, naming, or re-comparing a stored basket (all S-03); caching, queues, progress UI.

## Architecture / Approach

Three phases ordered so the riskiest touch on existing code comes first and alone. Phase 1 is a pure refactor with an existing safety net: lift the report markup out of `home.blade.php` into an anonymous Blade component, so phases 2 and 3 build on a shared render instead of a second copy that would start drifting immediately. Phase 2 adds the slice's only new state — a session basket wrapped in `BasketSession`, the single enforcement point for clamping and duplicate merging — puts the routes behind `auth`, and wires the guest round-trip end to end via Laravel's own `redirect()->guest()` intended-URL mechanism. Phase 3 connects them: „Porównaj" feeds `$basket->lines()` into `BasketComparator` and renders the phase-1 component, while every basket mutation forgets the comparison flag so a stale verdict can never sit under an edited basket.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Extract report component | `<x-comparison-report>`; homepage renders through it, output unchanged | Touching the only working page in the product — the north star, which has no impl-review yet. `HomePageTest` is the net. |
| 2. Session basket + builder | `BasketSession`, gated `/koszyk` routes, builder UI, guest→Google→builder redirect, auth-gate test | The intended-URL change edits freshly-closed F-02 auth code; and unclamped quantities reach `Money::times()` directly. |
| 3. Report on demand | „Porównaj" action, report rendered under the basket, stale-report handling, removable missing products | A stale verdict rendered next to an edited basket would be exactly the wrong-verdict failure the guardrail exists to prevent. |

**Prerequisites:** S-01 and F-02 both implemented (they are — `c79da6f`, `78555c8`). A running ddev and a Vite build. A real Google account for the phase-2 round-trip.
**Estimated effort:** ~2–3 sessions across 3 phases; phase 2 is the largest.

## Open Risks & Assumptions

- **Phase 1 edits unreviewed code.** S-01 never went through `/10x-impl-review`, so the extraction lands on top of code whose only verification is its own test suite. `HomePageTest` catches a dropped verdict or total, but it will not catch a subtly wrong mechanic label or a lost validity window — those need the manual check.
- **A four-product catalogue makes „zbuduj własny koszyk" a thin demo.** With `main_goal: market-feedback`, this is the slice you would want to show someone, and until F-03 unblocks there is very little to build a basket from. That is a deliberate trade, not an oversight.
- **The picker's `Product::orderBy('name')->get()` is unbounded.** Harmless over four rows; it is the first thing that breaks when F-03 floods the `products` table.
- **Session baskets vanish silently.** A logout, a cleared cookie or an expired session loses the basket with no warning, and S-03 will have to decide whether to carry a session basket into the database on save.
- **The stale-report rule depends on every mutation remembering to forget.** Mitigated by putting `forgetComparison()` inside `BasketSession`'s own write methods rather than in the controller, so no caller can skip it.
- **F-02's production database reset is still outstanding** and unrelated to this change, but it gates any deploy that follows.

## Success Criteria (Summary)

- A logged-in user builds a basket from the catalogue and gets a correct, promo-aware verdict with full per-line evidence — brand, gramatura, mechanic, validity window — on a phone.
- A guest who clicks through from the homepage lands back on the basket page after logging in, not on `/`.
- An incomplete basket never produces a winner: it names the products that blocked the verdict and lets the user remove them.
