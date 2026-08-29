# Basket Builder + Full Comparison Report Implementation Plan

## Overview

A logged-in user assembles their own basket from the seeded catalogue and runs the full Lidl vs Biedronka comparison on it — the same verdict, the same promo mechanics, the same matched-product evidence and validity windows the homepage already shows for the fixed example basket, computed by the same engine.

This is roadmap item **S-02**, the largest user-facing slice in the MVP. It adds no pricing logic: S-01 already built and tested that. What it adds is the path a real user walks — a basket they choose, behind the login F-02 wired, ending in the report FR-004 promises.

## Current State Analysis

Both prerequisites are implemented and neither is archived.

**S-01 left a fully reusable engine.** `BasketComparator::compare(array $basket, DateTimeInterface|string|null $date)` takes exactly `list<array{product: string, quantity: int}>` — the shape a user-built basket has. It is free of Eloquent mutation, loads every product with its listings and currently-valid entries in one eager-loaded pass (`app/Pricing/BasketComparator.php:60-84`), and enforces freshness at load time through `PriceEntry::validOn()`, so an expired price has no code path into a total. The whole-basket "brak danych" guardrail lives in `decide()` (`app/Pricing/BasketComparator.php:186-232`) and already returns the offending slugs in `Verdict::missingProducts`.

**The full report already renders — on the homepage.** `resources/views/home.blade.php` is 147 lines, of which roughly 120 are the report itself: verdict (one or two scenarios depending on `ComparisonReport::cardChangesOutcome()`), per-chain totals, and a per-line breakdown carrying the chain's own listing name, `brand`, `size_label`, the applied mechanic's label, the forced-overbuy warning, the with-card delta and the leaflet's from–to window. That markup is what FR-004 and FR-008 describe; it is not something this slice needs to invent.

**F-02 left the chrome ready and the redirect not.** `resources/views/layouts/app.blade.php:14` carries the comment *"The basket link lands here when S-02 arrives"*, and the header already branches on `@auth`. But `GoogleController::callback` ends with a hard `redirect('/')` (`app/Http/Controllers/Auth/GoogleController.php:67`), and `bootstrap/app.php` configures no `redirectGuestsTo` and no `login` route exists — so a route behind `auth` middleware would currently throw a `RouteNotFoundException` for `login` rather than sending a guest to Google.

**The catalogue is four products.** `ExampleBasketSeeder` builds its catalogue only for slugs listed in `config('koszykomat.example_basket')`: `mleko-32-1l`, `maslo-extra-200g`, `kawa-ziarnista-1kg`, `czekolada-mleczna-100g`. The real catalogue arrives with F-03, which is blocked on the vision-API vendor decision.

**There is no JavaScript and no basket schema.** `resources/js/app.js` is a single `//`; there is no Livewire, Alpine or Inertia. Migrations stop at `oauth_identities` — nothing models a basket.

## Desired End State

A logged-in user opens the basket page from the header or from the homepage call-to-action, adds products from the seeded catalogue, sets quantities, and presses „Porównaj". Below the basket a full report appears: the verdict naming the cheaper chain and the margin (or both card scenarios when the card changes the answer), each chain's total, and every line showing what that chain actually lists — brand, size, the mechanic applied, whether the promo wanted more items than were asked for, and the leaflet window the price came from.

If any line cannot be priced in some chain, no winner is named. The report says „brak danych", lists the products responsible, and offers to remove each one so the user can get to a verdict instead of a dead end.

Editing the basket after comparing hides the now-stale report and says the basket changed.

A guest who clicks the homepage call-to-action goes to Google and comes back **to the basket page**, not to `/`.

### Key Discoveries:

- `BasketComparator::compare()` accepts the user basket shape verbatim — `app/Pricing/BasketComparator.php:38`. No engine change is needed or wanted.
- `Verdict::missingProducts` already carries the slugs that suppressed the verdict — `app/Pricing/Verdict.php:23`, populated at `app/Pricing/BasketComparator.php:205`. The "name the culprit" behaviour is a rendering concern, not new logic.
- `ComparisonReport::cardChangesOutcome()` and `headline()` already decide one-verdict-vs-two — `app/Pricing/ComparisonReport.php:31-63`. The extracted component inherits that decision untouched.
- `layouts/app.blade.php` already renders `session('auth_error')` as an alert bar — the basket page needs no error chrome of its own.
- Laravel's `redirect()->guest()` (used by the framework's unauthenticated handler) stores the current URL as `url.intended` automatically, so intended-URL support needs only a `redirectGuestsTo` and a `redirect()->intended()`.
- Blade note from `context/foundation/lessons.md`: `@php(...)` is removed in Laravel 11+; the existing views correctly use the `@php … @endphp` block form and the new ones must too.

## What We're NOT Doing

- **No `baskets` / `basket_items` tables, no migration.** The basket lives in the session. Persistence is S-03's outcome and S-03 owns that schema.
- **No basket surviving logout or session expiry.** Same reason.
- **No JavaScript, no Livewire, no Alpine.** Every basket mutation is a POST followed by a redirect.
- **No seed expansion.** The builder works over the four seeded products; F-03 supplies the real catalogue.
- **No search, autocomplete, filtering, pagination or category browsing** in the product picker.
- **No change to any file under `app/Pricing/`.** The engine and its tests are untouched.
- **No limit on the number of distinct lines** in a basket.
- **No hiding or flagging of products that lack a valid price** — the whole-basket "brak danych" rule handles them after the fact.
- **No tests beyond the auth gate.** `HomePageTest` is retained as the regression net for phase 1, not extended.
- **No saved-basket list, no naming a basket, no re-comparing a stored basket** — all S-03.
- **No caching, queueing or progress UI.** The comparison is one eager-loaded query over a handful of rows.

## Implementation Approach

Three phases, ordered so the riskiest touch on existing code happens first and in isolation.

Phase 1 extracts the report markup out of `home.blade.php` into an anonymous Blade component. This is a pure refactor with an existing automated safety net (`HomePageTest`), and doing it first means phases 2 and 3 build on a shared render rather than creating a second copy that would immediately start drifting.

Phase 2 introduces the only new state in the slice: a session-backed basket, wrapped in a small service so no controller manipulates the session array directly. The routes go behind `auth`, the guest redirect is wired end-to-end, and the builder page ships without any report — verifiable on its own by adding, merging, re-quantifying, removing and clearing.

Phase 3 connects the two: an explicit „Porównaj" action feeds the session basket into `BasketComparator` and renders the phase-1 component. Basket mutations forget the comparison, so a stale report can never be read as a fresh one.

## Critical Implementation Details

**Intended-URL mechanics.** Laravel's unauthenticated handler calls `redirect()->guest(...)`, which writes the current URL into the session as `url.intended` before redirecting. That means intended-URL support needs two small things and no custom middleware: a `redirectGuestsTo` callback in `bootstrap/app.php` pointing at `auth.google.redirect`, and `GoogleController::callback`'s final `redirect('/')` becoming `redirect()->intended('/')`. The error branches at lines 40, 50 and 56 keep their hard `redirect('/')` — a failed login should land on the page that can explain itself, not bounce back into a gated route.

**Quantity clamping is a money-safety concern, not a UI nicety.** `PromoCalculator` multiplies through `Money::times($quantity)`, so an unclamped quantity from a hand-crafted POST produces an absurd but entirely plausible-looking total. Clamp on write, in the basket service, not in the Blade form — the form's `max` attribute is a hint, the service is the boundary.

**Staleness must be forgotten, not recomputed.** After „Porównaj" the page holds a report for the basket as it was. Any mutation must clear the comparison flag in the same request that mutates, so there is no window where the page can render yesterday's verdict next to today's basket. Recomputing on every mutation would also work but contradicts the explicit-action decision from US-01.

**The session basket is a list, and its order is the report's order.** `ComparisonReport::basketLines` preserves the order the basket declared, and the component renders in that order. Storing the basket as a slug-keyed map and re-deriving a list on read keeps duplicate-merging trivial while giving stable ordering — insertion order, which PHP arrays preserve.

---

## Phase 1: Extract the comparison report into a Blade component

### Overview

Move the report markup out of the homepage into a reusable anonymous Blade component, leaving the homepage's own framing (title, lead paragraph) behind. Nothing about the rendered output changes.

### Changes Required:

#### 1. The report component

**File**: `resources/views/components/comparison-report.blade.php` (new)

**Intent**: Hold every piece of markup that describes a `ComparisonReport` — the verdict block(s), the per-chain totals, the per-line breakdown, and the closing note about data freshness and conditional promos — so that the homepage and the user's basket report render from one source.

**Contract**: Anonymous Blade component taking a single required prop, used as `<x-comparison-report :report="$report" />` where `$report` is an `App\Pricing\ComparisonReport`. Declare the prop with `@props(['report'])`. The component covers exactly the current `home.blade.php` from the `@php $scenarios = …` block (line 16) through the closing `</footer>` (line 145) inclusive — the verdict `<section>`, the details `<section>` and the `<footer>`. It renders no `<main>` and no page heading. Use the `@php … @endphp` block form, per `context/foundation/lessons.md`.

#### 2. The homepage

**File**: `resources/views/home.blade.php`

**Intent**: Keep only what is specific to the guest demo — the page shell, the `<h1>` and the lead paragraph explaining that this is an example basket — and delegate the report itself to the component.

**Contract**: After this change the file retains `@extends`, `@section('title', …)`, the `<main>` wrapper and its intro `<header>`, then a single `<x-comparison-report :report="$report" />`. `HomeController` is not touched: it still passes `report` to the view.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- Homepage test passes unchanged: `ddev artisan test tests/Feature/HomePageTest.php`
- Code style passes: `ddev composer lint`
- Frontend builds: `ddev npm run build`
- `home.blade.php` contains no verdict or per-line markup — only the page intro and the component tag

#### Manual Verification:

- The homepage renders identically to before: verdict (one or both card scenarios), both chain totals, all four lines with brand, size, mechanic label, validity window, and the closing note
- Page still usable single-column at 375 px with no horizontal scroll

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Session-backed basket and the builder page

### Overview

Introduce the basket as session state behind a small service, put it behind `auth` with a working guest redirect, and ship the builder UI — add, merge duplicates, change quantity, remove a line, clear the basket, and an empty state. No report yet.

### Changes Required:

#### 1. Basket configuration

**File**: `config/koszykomat.php`

**Intent**: Give the quantity bounds a named home next to the example basket rather than scattering literals through the service and the form.

**Contract**: Add a `basket` key holding `min_quantity` (1) and `max_quantity` (99), documented in the same comment style as `example_basket`, noting that the cap exists because `Money::times()` will faithfully multiply whatever quantity reaches it.

#### 2. The basket session service

**File**: `app/Basket/BasketSession.php` (new)

**Intent**: Own every read and write of the basket in the session so no controller touches the session array directly, and so quantity clamping and duplicate merging have exactly one enforcement point.

**Contract**: A final class with a session key constant (e.g. `basket.lines`) and these operations: `lines(): list<array{product: string, quantity: int}>` returning the basket in the engine's shape and in insertion order; `add(string $slug, int $quantity = 1): void` which merges into an existing line by summing and then clamping; `setQuantity(string $slug, int $quantity): void`; `remove(string $slug): void`; `clear(): void`; `isEmpty(): bool`. Internally the basket is a slug-keyed map so merging is a single array lookup; `lines()` re-derives the list. Every write path clamps to `config('koszykomat.basket.min_quantity')`–`max_quantity`; a quantity below the minimum on `setQuantity` removes the line instead of storing zero.

#### 3. Guest redirect wiring

**File**: `bootstrap/app.php`

**Intent**: Send an unauthenticated visitor who hits a gated route to Google, since this application has no `login` route and the framework's default would throw.

**Contract**: Inside the existing `withMiddleware` closure, add `$middleware->redirectGuestsTo(fn () => route('auth.google.redirect'));`. Leave `trustProxies` as is.

**File**: `app/Http/Controllers/Auth/GoogleController.php`

**Intent**: Return a successfully-authenticated user to whatever gated page sent them to Google, falling back to the homepage.

**Contract**: The single success-path `return redirect('/')` at line 67 becomes `return redirect()->intended('/')`. The three failure branches (lines 40, 50, 56) keep their literal `redirect('/')` — a login that failed must land somewhere that can show `auth_error`, not re-enter a gated route.

#### 4. Basket routes

**File**: `routes/web.php`

**Intent**: Expose the builder and its mutations to authenticated users only.

**Contract**: A `Route::middleware('auth')->prefix('koszyk')->name('basket.')->group(...)` containing `GET /` → `BasketController@show` (name `index`), `POST /pozycje` → `store`, `PATCH /pozycje/{product}` → `update`, `DELETE /pozycje/{product}` → `destroy`, and `DELETE /` → `clear`. `{product}` is a product slug, not an id. Forms use `@method` spoofing for PATCH/DELETE; CSRF applies as usual.

#### 5. The basket controller

**File**: `app/Http/Controllers/BasketController.php` (new)

**Intent**: Translate form posts into `BasketSession` calls and render the builder; keep it thin, with no pricing and no session access of its own.

**Contract**: Constructor-injects `BasketSession`. `show()` returns the `basket.index` view with the current lines resolved to `Product` models for display (one `whereIn('slug', …)` query) and the full catalogue for the picker (`Product::orderBy('name')->get()`). `store()` validates `product` as a required slug that `exists:products,slug` and `quantity` as an optional integer within the configured bounds, then delegates and redirects back to `basket.index`. `update()`, `destroy()` and `clear()` likewise delegate and redirect back. Every mutation redirects rather than rendering, so a refresh never re-posts.

#### 6. The builder view

**File**: `resources/views/basket/index.blade.php` (new)

**Intent**: Let the user assemble a basket on a phone with plain forms — pick a product, see the lines, adjust quantities, remove lines, clear everything — and show an inviting empty state rather than a comparison of nothing.

**Contract**: Extends `layouts.app`. Contains: a picker form (a `<select>` over the catalogue plus a quantity `<input type="number" min max>` and an „Dodaj" submit) posting to `basket.store`; a list of current lines, each with the product name, an inline quantity form patching `basket.update`, and a delete form hitting `basket.destroy`; a „Wyczyść koszyk" form hitting `basket.clear`; and, when the basket is empty, a short Polish encouragement instead of the line list. Mobile-first single column, Tailwind utilities consistent with `home.blade.php`. All user-facing strings in Polish.

#### 7. Homepage call-to-action

**File**: `resources/views/home.blade.php`

**Intent**: Convert the guest demo into the product's main flow — the homepage proves the verdict is worth trusting, so that is where the invitation to build your own basket belongs.

**Contract**: A prominent link to `route('basket.index')` added below the report, labelled for a guest as an invitation to build their own basket. The link is rendered for guests and authenticated users alike; the `auth` middleware turns a guest's click into the Google round-trip. Do not branch on `@auth` here.

#### 8. Auth gate test

**File**: `tests/Feature/Basket/BasketAccessTest.php` (new)

**Intent**: Pin the one security-shaped guarantee in this slice: the basket is not reachable without a session.

**Contract**: Two cases — a guest `GET /koszyk` is redirected (not 200, not 500) and lands on the Google redirect route; an authenticated user (via `actingAs(User::factory()->create())`) gets 200. Uses `RefreshDatabase` like the existing feature tests.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- Auth-gate test passes: `ddev artisan test tests/Feature/Basket/BasketAccessTest.php`
- Code style passes: `ddev composer lint`
- Frontend builds: `ddev npm run build`
- Routes registered as expected: `ddev artisan route:list --except-vendor`

#### Manual Verification:

- A guest clicking the homepage call-to-action reaches Google and returns to `/koszyk`, not to `/`
- Adding the same product twice produces one line with quantity 2, not two lines
- Quantity is clamped to 1–99 even when the form is bypassed; setting 0 removes the line
- An empty basket shows the Polish encouragement, not an error and not „brak danych"
- Removing a single line and clearing the whole basket both work and survive a page refresh
- Builder usable single-column at 375 px with no horizontal scroll

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: The comparison report on demand

### Overview

Wire „Porównaj" to the pricing engine, render the phase-1 component beneath the basket, forget the report whenever the basket changes, and turn the „brak danych" verdict into an actionable list rather than a dead end.

### Changes Required:

#### 1. Comparison state in the basket session

**File**: `app/Basket/BasketSession.php`

**Intent**: Remember that the user asked for a comparison, and forget it the moment the basket no longer matches — so a stale verdict can never be rendered next to an edited basket.

**Contract**: Add `markCompared(): void`, `forgetComparison(): void` and `wantsComparison(): bool` over a second session key (e.g. `basket.compared`). Every existing mutation (`add`, `setQuantity`, `remove`, `clear`) calls `forgetComparison()` internally, so no caller can forget to.

#### 2. The compare action

**File**: `routes/web.php`, `app/Http/Controllers/BasketController.php`

**Intent**: Make comparing an explicit user action, as US-01 describes, rather than a side effect of editing.

**Contract**: A `POST /porownaj` route inside the existing authenticated group (name `basket.compare`) mapping to `BasketController@compare`, which marks the session as compared and redirects back to `basket.index`. `show()` gains a `BasketComparator` parameter and, when `wantsComparison()` is true and the basket is non-empty, passes a `report` built from `$basket->lines()` to the view; otherwise passes `null`.

#### 3. The report on the builder page

**File**: `resources/views/basket/index.blade.php`

**Intent**: Show the user's own report using exactly the same component the homepage uses, and tell them plainly when what they are looking at no longer matches their basket.

**Contract**: A „Porównaj" submit button posting to `basket.compare`, disabled or hidden when the basket is empty. When `$report` is present, render `<x-comparison-report :report="$report" />` below the basket. When the basket has been edited since the last comparison — that is, `$report` is null but the basket is non-empty and had been compared before — show a short Polish note that the basket changed and the comparison needs re-running. Because mutations forget the flag, this is a render-time branch, not extra state.

#### 4. Actionable „brak danych"

**File**: `resources/views/components/comparison-report.blade.php`

**Intent**: Turn the guardrail's refusal into something the user can act on: the verdict block already names the products that blocked it, so each one should be removable in a click.

**Contract**: In the no-data branch of the verdict block, render each entry of `$verdict->missingProducts` with a small form posting `DELETE` to `basket.destroy` for that slug, labelled in Polish as removing it from the basket. Guard the whole affordance behind a `@props` flag (e.g. `@props(['report', 'removableMissing' => false])`) so the homepage — whose fixed basket the guest cannot edit — renders the plain list it renders today. The basket page passes `:removable-missing="true"`.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- Code style passes: `ddev composer lint`
- Frontend builds: `ddev npm run build`
- Homepage test still passes: `ddev artisan test tests/Feature/HomePageTest.php`

#### Manual Verification:

- „Porównaj" renders the verdict, both chain totals and the full per-line breakdown for the user's own basket, identical in shape to the homepage
- Editing the basket after comparing hides the report and shows the „koszyk się zmienił" note
- A basket containing a product with no valid price in one chain yields „brak danych", names the product, and offers to remove it
- Removing the named product and comparing again produces a real verdict
- The homepage's no-data rendering is unchanged — no remove buttons appear there
- Report readable single-column at 375 px; the comparison feels well within the <2 s budget

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

`CLAUDE.md` makes tests optional during the MVP except for the four promo mechanics, which S-01 already covers at engine level. This slice adds one test and leans on one existing test.

### Unit Tests:

None. The only new logic — session read/write with clamping and merging — is verified manually per the decision recorded in the brief.

### Integration Tests:

- `tests/Feature/Basket/BasketAccessTest.php` — the auth gate: a guest is redirected to the Google route, an authenticated user gets 200.
- `tests/Feature/HomePageTest.php` (existing, unmodified) — the regression net for the phase-1 extraction. It asserts the homepage still renders the verdict and both chain totals, which is exactly what a botched component extraction would break.

### Manual Testing Steps:

1. Log out. From the homepage, click the call-to-action; confirm Google, and confirm you land on `/koszyk`.
2. Add a product; add the same product again; confirm one line with quantity 2.
3. Set a quantity to 0 and confirm the line disappears; try to POST a quantity of 100000 and confirm it is clamped to 99.
4. Press „Porównaj"; confirm the verdict, both totals, and per-line brand, size, mechanic and validity window.
5. Change a quantity; confirm the report disappears and the „koszyk się zmienił" note shows.
6. Build a basket containing a product with no valid price in one chain; confirm „brak danych", the named product, and that removing it then comparing yields a verdict.
7. Repeat steps 2–6 at 375 px width and confirm no horizontal scroll.

## Performance Considerations

The comparison is a single eager-loaded query pass regardless of basket size — `BasketComparator::resolveBasketLines()` fetches every product with its listings and valid entries in one `whereIn`, and both scenarios then run over in-memory models. Adding user-chosen products changes the number of slugs in that `whereIn`, not the number of queries, so the <2 s NFR is not at risk from this slice.

The builder page itself issues two queries: the catalogue for the picker and the basket lines for display. Both are bounded by the seeded catalogue's four products until F-03 lands. When F-03 floods the `products` table, the unbounded `Product::orderBy('name')->get()` in the picker is the first thing that will need attention — it is called out here so it is not a surprise.

## Migration Notes

None. This change adds no migration, alters no table, and writes nothing to the database. A deploy carries no schema risk and rolls back cleanly.

Note that the production database reset owed from F-02 is still outstanding and independent of this change; see `context/changes/oauth-authentication/plan.md` → Migration Notes.

## References

- Roadmap item: `context/foundation/roadmap.md` — S-02
- Pricing engine reused unchanged: `app/Pricing/BasketComparator.php:38`
- Report markup extracted in phase 1: `resources/views/home.blade.php:16-145`
- Auth surface this builds on: `app/Http/Controllers/Auth/GoogleController.php:67`, `resources/views/layouts/app.blade.php:14`
- Blade constraint: `context/foundation/lessons.md` — "Use the @php … @endphp block form in Blade"
- Prior slice: `context/changes/guest-fixed-basket-comparison/plan.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Extract the comparison report into a Blade component

#### Automated

- [x] 1.1 Full test suite passes: `ddev composer test` — da5cdba
- [x] 1.2 Homepage test passes unchanged: `ddev artisan test tests/Feature/HomePageTest.php` — da5cdba
- [x] 1.3 Code style passes: `ddev composer lint` — da5cdba
- [x] 1.4 Frontend builds: `ddev npm run build` — da5cdba
- [x] 1.5 `home.blade.php` contains no verdict or per-line markup — only the page intro and the component tag — da5cdba

#### Manual

- [x] 1.6 Homepage renders identically to before: verdict, both chain totals, all four lines with brand, size, mechanic label, validity window, closing note — da5cdba
- [x] 1.7 Page still usable single-column at 375 px with no horizontal scroll — da5cdba

### Phase 2: Session-backed basket and the builder page

#### Automated

- [x] 2.1 Full test suite passes: `ddev composer test` — 84f6f99
- [x] 2.2 Auth-gate test passes: `ddev artisan test tests/Feature/Basket/BasketAccessTest.php` — 84f6f99
- [x] 2.3 Code style passes: `ddev composer lint` — 84f6f99
- [x] 2.4 Frontend builds: `ddev npm run build` — 84f6f99
- [x] 2.5 Routes registered as expected: `ddev artisan route:list --except-vendor` — 84f6f99

#### Manual

- [x] 2.6 A guest clicking the homepage call-to-action reaches Google and returns to `/koszyk`, not to `/` — 84f6f99
- [x] 2.7 Adding the same product twice produces one line with quantity 2, not two lines — 84f6f99
- [x] 2.8 Quantity clamped to 1–99 even when the form is bypassed; setting 0 removes the line — 84f6f99
- [x] 2.9 Empty basket shows the Polish encouragement, not an error and not „brak danych" — 84f6f99
- [x] 2.10 Removing a single line and clearing the whole basket both work and survive a refresh — 84f6f99
- [x] 2.11 Builder usable single-column at 375 px with no horizontal scroll — 84f6f99

### Phase 3: The comparison report on demand

#### Automated

- [x] 3.1 Full test suite passes: `ddev composer test` — b3f3d11
- [x] 3.2 Code style passes: `ddev composer lint` — b3f3d11
- [x] 3.3 Frontend builds: `ddev npm run build` — b3f3d11
- [x] 3.4 Homepage test still passes: `ddev artisan test tests/Feature/HomePageTest.php` — b3f3d11

#### Manual

- [x] 3.5 „Porównaj" renders verdict, both chain totals and the full per-line breakdown for the user's own basket — b3f3d11
- [x] 3.6 Editing the basket after comparing hides the report and shows the „koszyk się zmienił" note — b3f3d11
- [x] 3.7 A basket with a product lacking a valid price in one chain yields „brak danych", names it, and offers removal — b3f3d11
- [x] 3.8 Removing the named product and comparing again produces a real verdict — b3f3d11
- [x] 3.9 The homepage's no-data rendering is unchanged — no remove buttons appear there — b3f3d11
- [x] 3.10 Report readable single-column at 375 px; comparison feels well within the <2 s budget — b3f3d11
