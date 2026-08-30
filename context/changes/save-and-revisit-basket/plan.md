# Save and Revisit a Basket — Implementation Plan

## Overview

Give a logged-in user somewhere to keep the basket they just built. Today the basket lives only in
the session (`app/Basket/BasketSession.php`), so a logout, a cleared cookie or an expired session
loses it silently. This change adds owner-scoped persistence: name and save the current basket, see
your saved baskets on a list page, load one back into the basket to re-compare it against whatever
price data is current, and delete the ones you no longer want.

This is roadmap slice **S-03** and it closes **FR-005**. The load-bearing requirement is not the
CRUD — it is the privacy NFR: *"zapisane koszyki są widoczne wyłącznie dla właściciela konta"*.

## Current State Analysis

**The basket is session-only, on purpose.** S-02's plan states it outright: *"No `baskets` /
`basket_items` tables, no migration. Persistence is S-03's outcome and S-03 owns that schema."*
This change designs that schema from scratch, with no legacy shape to honour.

**`BasketSession` is the single enforcement point** for three rules: quantity clamping to
`config('koszykomat.basket.{min,max}_quantity')`, duplicate-line merging, and comparison
invalidation. Every mutator funnels into one private `store()`
(`app/Basket/BasketSession.php:154`), which writes the line map, forgets the `basket.compared`
flag, and flashes a stale note when a report was actually discarded. Anything that writes basket
lines without going through `store()` bypasses all three — a loaded basket sitting under a verdict
computed from a different basket is exactly the wrong-verdict failure the PRD guardrail exists to
prevent.

**Re-comparison needs no snapshot.** `BasketComparator::compare(array $basket, ?date)`
(`app/Pricing/BasketComparator.php:33`) takes a plain `list<array{product: string, quantity: int}>`
and resolves every price through `PriceEntry::usableOn($on)` at call time. A saved basket that
stores only products and quantities re-prices itself correctly after a nightly refresh, which is
precisely what FR-005 asks for. Storing prices would actively break it.

**Auth and ownership conventions are established.** `oauth_identities`
(`database/migrations/2026_08_29_100000_create_oauth_identities_table.php`) uses
`foreignId('user_id')->constrained()->cascadeOnDelete()`; `User` already declares a `HasMany`
relation to it. Routes for the logged-in half of the product sit behind
`Route::middleware('auth')` with Polish URL slugs and an English route-name prefix
(`routes/web.php:24`).

**Enum-ish columns are plain strings cast in PHP, never DDL enums** — the suite runs on in-memory
SQLite while production is MySQL 8.0. Nothing in this change needs an enum, but the same
SQLite/MySQL split constrains the migrations: no MySQL-only column types.

## Desired End State

A logged-in user with a non-empty basket sees a "save this basket" form on `/koszyk`, gives it a
name, and lands on `/koszyki` with the basket listed. They can come back days later — after the
nightly refresh has replaced the underlying prices — click "Wczytaj", land back on `/koszyk` with
exactly the products and quantities they saved, press "Porównaj", and get a verdict computed from
today's data. Signed in as a different account, the same saved-basket URL is a 404.

Verified by: the two feature tests in Phases 2 and 3, plus the manual walkthrough in
`## Testing Strategy`.

### Key Discoveries:

- `BasketSession::store()` (`app/Basket/BasketSession.php:154`) is the only write path and the only
  place comparison invalidation happens — loading a saved basket must route through it.
- `BasketComparator::compare()` (`app/Pricing/BasketComparator.php:33`) consumes
  `list<array{product: string, quantity: int}>`, the exact shape `BasketSession::lines()` returns —
  a saved basket only has to reproduce that array.
- `x-comparison-report` renders a `route('basket.destroy', $slug)` form when
  `:removable-missing="true"` (`resources/views/components/comparison-report.blade.php:52`) — it is
  coupled to the *session* basket, so it must not be rendered from the saved-basket list page.
- `lessons.md`: *"Never let two related factories each create their own parent"* — `SavedBasketItem`'s
  factory must derive its `saved_basket_id` and `user_id` consistently rather than spawning a second
  `SavedBasket`.
- `lessons.md`: `@php($x = ...)` is removed in Laravel 11+; use the `@php … @endphp` block form.
  `basket/index.blade.php:88` already follows this.
- `config/koszykomat.php` is where basket bounds live, with a comment explaining *why* the cap is
  not cosmetic — the saved-basket cap belongs beside it, not scattered in validation rules.

## What We're NOT Doing

- **No price or verdict snapshot.** A saved basket stores products and quantities only. FR-005 asks
  for *re-comparison after a refresh*; a stored verdict would be a stale answer wearing a fresh
  timestamp.
- **No update-in-place.** Saving always inserts a new named basket. There is no session-side
  "origin basket" pointer, so no code path can overwrite the wrong saved basket.
- **No renaming.** Delete and save again.
- **No unique `(user_id, name)` constraint.** It would reject the save-edit-save-again loop that
  "always a new save" implies. Duplicate names are allowed; the list disambiguates by save date.
- **No sharing, no public baskets, no export.** Owner-only, per the NFR.
- **No JavaScript, Livewire or Alpine.** S-02's constraint carries forward — the replace warning is
  server-rendered, not a `confirm()`.
- **No pagination, search or sorting** on the list page. The per-account cap bounds it.
- **No changes under `app/Pricing/`.** The comparator already does everything this slice needs.
- **No guest saving.** Saving is behind `auth`, like the rest of `/koszyk`.
- **No soft deletes.** A deleted saved basket is gone.

## Implementation Approach

Three phases, each independently verifiable. Phase 1 lays the schema and models with no user-facing
surface. Phase 2 adds save/list/delete — the write path and the ownership boundary, which is where
the slice's real risk sits. Phase 3 adds the load action, which is the only place this change
touches existing code (`BasketSession`), and the only place a bug could corrupt the live basket.

Ownership is enforced by **scoping the query, not by checking after the fetch**: every saved-basket
lookup starts from `$request->user()->savedBaskets()`, so a foreign id simply is not in the result
set and `findOrFail()` produces a 404. A 404 rather than a 403 is deliberate — a 403 confirms the
basket exists, which is itself a leak across accounts.

## Critical Implementation Details

**State sequencing on load.** Loading a saved basket must go through `BasketSession`'s single write
path so the `basket.compared` flag is cleared in the same operation that replaces the lines. If the
lines are replaced while a comparison flag survives, `BasketController::show()` will render a report
computed from the *previous* basket directly beneath the newly loaded one. Clearing and re-adding
line by line would also work but fires the stale-comparison flash repeatedly; a single
replace-in-one-write is the correct shape.

**Quantity clamping applies on the way out, not just on the way in.** A saved row's quantity was
clamped when saved, but `config('koszykomat.basket.max_quantity')` can be lowered later, leaving
stored rows above the current cap. Loading must clamp again rather than trusting the database —
`PromoCalculator` multiplies through `Money::times($quantity)` with no limit of its own.

---

## Phase 1: Schema, models and factories

### Overview

The persistence layer, with no route and no view. Nothing user-facing changes; the phase is done
when the migrations apply and the models round-trip through factories.

### Changes Required:

#### 1. Migrations

**File**: `database/migrations/2026_08_30_110000_create_saved_baskets_table.php`

**Intent**: One named basket belonging to one account. Deleting the account takes its saved baskets
with it — there is no other owner they could belong to.

**Contract**: `id`, `user_id` (`foreignId()->constrained()->cascadeOnDelete()`), `name` (string),
`timestamps()`. Index on `user_id` for the list query — `constrained()` gives this on MySQL via the
foreign key, but declare the intent in the docblock. No unique constraint on `(user_id, name)`; see
"What We're NOT Doing".

**File**: `database/migrations/2026_08_30_110001_create_saved_basket_items_table.php`

**Intent**: One line of a saved basket: which product, how many. The cascade on `product_id` is the
decided behaviour — a product that leaves the catalogue leaves every saved basket that referenced
it, rather than blocking catalogue maintenance or leaving a dangling reference the report would have
to render as a phantom line.

**Contract**: `id`, `saved_basket_id` (`constrained()->cascadeOnDelete()`), `product_id`
(`constrained()->cascadeOnDelete()`), `quantity` (unsigned small integer), `timestamps()`. Unique on
`(saved_basket_id, product_id)` — the session basket merges duplicates by construction, so two rows
for one product in one saved basket is a shape the application cannot produce and the database
should not accept.

#### 2. Models

**File**: `app/Models/SavedBasket.php`

**Intent**: The saved basket and its relations. Carries the conversion back into the array shape the
pricing engine and `BasketSession` both speak, so that mapping lives in one place instead of being
re-derived in the controller.

**Contract**: `#[Fillable(['name'])]` (never `user_id` — ownership is set through the relation, not
through mass assignment). `belongsTo(User)`, `hasMany(SavedBasketItem)`. A method returning
`list<array{product: string, quantity: int}>` built from the items' products' slugs — the same shape
`BasketSession::lines()` returns.

**File**: `app/Models/SavedBasketItem.php`

**Intent**: One line. Thin.

**Contract**: `#[Fillable(['product_id', 'quantity'])]`, `belongsTo(SavedBasket)`,
`belongsTo(Product)`, `quantity` cast to `integer`.

**File**: `app/Models/User.php`

**Intent**: Give the user its saved baskets, which is also the scoping root every controller lookup
starts from.

**Contract**: add `savedBaskets(): HasMany<SavedBasket, $this>`.

#### 3. Factories

**File**: `database/factories/SavedBasketFactory.php`, `database/factories/SavedBasketItemFactory.php`

**Intent**: Test fixtures. The item factory must not spawn its own `SavedBasket` *and* its own
`Product` in a way that produces a row shape production cannot hold.

**Contract**: `SavedBasketFactory` creates a `User` for `user_id`. `SavedBasketItemFactory` resolves
`saved_basket_id` from a `SavedBasket` factory and `product_id` from a `Product` factory (these two
are genuinely independent — a saved basket and a product have no shared parent — so the lesson's
constraint is satisfied without extra plumbing). Quantity defaults within
`config('koszykomat.basket.{min,max}_quantity')`.

#### 4. Configuration

**File**: `config/koszykomat.php`

**Intent**: The per-account cap and the name length bound, beside the existing basket bounds and for
the same reason: these numbers are enforced in more than one place (validation, the list view's
"you're at the limit" state), so they get one home.

**Contract**: extend the `basket` block — or add a sibling `saved` block — with
`max_per_user` and `max_name_length`. Suggested values: 20 and 60. Document *why* the cap exists
(bounded list, bounded per-account rows), matching the tone of the existing `max_quantity` comment.

### Success Criteria:

#### Automated Verification:

- Migrations apply cleanly: `ddev artisan migrate:fresh`
- Existing suite still passes: `ddev composer test`
- Code style passes: `ddev composer lint`

#### Manual Verification:

- `ddev artisan tinker` — a `SavedBasket` factory with items round-trips, and the array-shape method
  returns the same structure `BasketSession::lines()` does
- Deleting a `User` removes their saved baskets and items; deleting a `Product` removes only the
  items referencing it, leaving the basket row intact

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human that the manual testing was successful before proceeding
to the next phase.

---

## Phase 2: Save, list and delete — owner-scoped

### Overview

The write path and the ownership boundary. After this phase a user can save the basket they built,
see it listed, and delete it — but not yet load it back. This is where the slice's real risk lives,
so the privacy test lands here.

### Changes Required:

#### 1. Routes

**File**: `routes/web.php`

**Intent**: A saved-baskets group alongside the existing basket group, behind the same `auth`
middleware. Polish URL slug, English route-name prefix — the convention `/koszyk` + `basket.`
already sets.

**Contract**: `Route::middleware('auth')->prefix('koszyki')->name('saved.')` with
`GET /` → `index`, `POST /` → `store`, `DELETE /{savedBasket}` → `destroy`. Bind by id, **not** by
implicit route-model binding — implicit binding resolves globally and would fetch another account's
basket before any scoping runs. The controller resolves through the user relation instead. (The load
route arrives in Phase 3.)

#### 2. Controller

**File**: `app/Http/Controllers/SavedBasketController.php`

**Intent**: Save the current session basket under a name, list what the account has saved, delete
one. Thin, like `BasketController` — the ownership rule is the only thing here worth getting right.

**Contract**:
- `index(Request)` — renders the list from `$request->user()->savedBaskets()`, eager-loading
  `items.product` so the per-basket item count and product names cost one query, not N (the
  no-N+1 constraint in CLAUDE.md). Newest first.
- `store(Request, BasketSession)` — validates `name` (`required|string|max:` the configured length),
  refuses an empty session basket, refuses when the account is at `max_per_user`, then creates the
  basket through `$request->user()->savedBaskets()->create(...)` and its items from
  `BasketSession::lines()`, resolving slugs to product ids in one `whereIn` query. Redirects to
  `saved.index`. Wrap the basket + items insert in a transaction — a saved basket with half its
  lines is a basket that will re-compare to a wrong total.
- `destroy(Request, int $id)` — `$request->user()->savedBaskets()->findOrFail($id)->delete()`.

Rejections are validation errors, so the existing `$errors` block on the basket page
(`resources/views/basket/index.blade.php:22`) renders them with no new markup. Messages in Polish.

#### 3. Save form on the basket page

**File**: `resources/views/basket/index.blade.php`

**Intent**: Offer the save only when there is something to save, next to the existing "Porównaj"
action.

**Contract**: inside the `@if ($lines === [])` … `@else` branch, a `POST` form to `route('saved.store')`
with a text input for the name and a submit button. Styling follows the existing form blocks in this
file. Polish labels.

#### 4. List view

**File**: `resources/views/saved/index.blade.php`

**Intent**: The revisit surface — what you saved, when, how many items, and the actions.

**Contract**: extends `layouts.app`, `@section('title')` in Polish. Per basket: name, save date,
item count, a delete form (`DELETE` to `saved.destroy`). An empty state pointing back to `/koszyk`.
A note when the account is at the cap. Does **not** render `x-comparison-report` — that component's
`removable-missing` path posts to `basket.destroy`, which edits the session basket.

#### 5. Header navigation

**File**: `resources/views/layouts/app.blade.php`

**Intent**: Saved baskets are unreachable without a link. The header is already built as a bar for
exactly this.

**Contract**: inside `@auth`, links to `route('basket.index')` and `route('saved.index')`.

#### 6. Privacy test

**File**: `tests/Feature/Basket/SavedBasketPrivacyTest.php`

**Intent**: Prove the NFR the roadmap flags as this slice's load-bearing requirement — that a saved
basket is invisible to every account but its owner. The oracle is the PRD NFR
(*"widoczne wyłącznie dla właściciela konta"*), not the controller's code.

**Contract**: `RefreshDatabase`. Assert that a second account (a) does not see the first account's
basket on `saved.index`, and (b) gets **404** — not 403, not 200 — when deleting it by id, and that
the basket still exists afterwards. Also assert a guest is redirected to
`route('auth.google.redirect')`, matching `BasketAccessTest`'s reasoning: there is no `login` route,
so a misconfigured gate fails as a 500 rather than a redirect.

### Success Criteria:

#### Automated Verification:

- The privacy test passes: `ddev artisan test tests/Feature/Basket/SavedBasketPrivacyTest.php`
- Full suite passes: `ddev composer test`
- Code style passes: `ddev composer lint`

#### Manual Verification:

- Building a basket on `/koszyk`, naming it and saving lands on `/koszyki` with the basket listed
- Saving with a blank name, from an empty basket, or past the cap shows a Polish validation message
  and saves nothing
- The list page shows name, date and item count with no repeated queries (verify with
  `DB::listen` in tinker or the query log)
- Delete removes the basket and its items
- The page is usable on a phone-width viewport (mobile-first NFR)

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human that the manual testing was successful before proceeding
to the next phase.

---

## Phase 3: Load into the session and re-compare

### Overview

Closes the loop FR-005 actually describes: return to a saved basket and re-compare it against
current data. The only phase that modifies existing code.

### Changes Required:

#### 1. Session replacement

**File**: `app/Basket/BasketSession.php`

**Intent**: Replace the whole basket in one write, so the loaded lines and the cleared comparison
flag land together. Adding a public mutator here — rather than letting the controller assemble the
session — keeps the class's stated invariant true: every read and write of the basket goes through
this one object.

**Contract**: a public method taking `list<array{product: string, quantity: int}>`, building the
slug-keyed map with the existing `clamp()` applied per line, and calling the existing private
`store()` exactly once. Clamping on load is not redundant: `max_quantity` may have been lowered
since the basket was saved.

#### 2. Load route and action

**File**: `routes/web.php`, `app/Http/Controllers/SavedBasketController.php`

**Intent**: Load a saved basket into the session and drop the user on the basket page, where the
existing "Porównaj" button re-prices it against today's data.

**Contract**: `POST /koszyki/{savedBasket}/wczytaj` → `saved.load`. `POST`, not `GET` — it mutates
session state, so it needs the CSRF token; a `GET` load could be fired by any link or prefetch on
another site, the same reasoning `routes/web.php:21` gives for `POST /logout`. The action resolves
through `$request->user()->savedBaskets()` (404 for a foreign id), eager-loads `items.product`,
converts to the array shape and hands it to the session replacement, then redirects to
`basket.index` with a Polish confirmation in the flash bag.

#### 3. Replace warning on the list page

**File**: `resources/views/saved/index.blade.php`

**Intent**: Loading discards whatever is in the session basket. With no JavaScript there is no
`confirm()`, so the warning is server-rendered and shown only when there is actually something to
lose.

**Contract**: the controller's `index` passes whether the session basket is non-empty; the view
renders a note above the list when it is, in the amber styling the layout and basket page already
use for advisory messages. Each basket gets a "Wczytaj" submit button posting to `saved.load`.

#### 4. Load-fidelity test

**File**: `tests/Feature/Basket/SavedBasketLoadTest.php`

**Intent**: Prove the save→load round trip preserves exactly the products and quantities that were
saved. Quantities are not cosmetic here — every conditional promo mechanic in FR-007 keys off them,
so a dropped or doubled quantity is a wrong verdict, not a display bug. The oracle is the basket the
test saved, never the loader's own output.

**Contract**: `RefreshDatabase`. Save a multi-line basket with distinct quantities (at least one
above 1, so a quantity that silently collapsed to the default would fail), clear the session, load
it back, and assert the session basket's lines equal the saved lines — same products, same
quantities, no extras. Add one edge case: a saved quantity above the current
`config('koszykomat.basket.max_quantity')` loads clamped to the cap, not verbatim.

#### 5. Roadmap sync

**File**: `context/foundation/roadmap.md`

**Intent**: Reflect that S-03 is in flight. `/10x-plan` flips it to `planning`; `/10x-implement`
takes it to `in-progress`; `/10x-archive` closes it.

**Contract**: the `S-03` row in `## At a glance` and the `- **Status:**` line in the `### S-03` body.

### Success Criteria:

#### Automated Verification:

- The load-fidelity test passes: `ddev artisan test tests/Feature/Basket/SavedBasketLoadTest.php`
- Full suite passes: `ddev composer test`
- Code style passes: `ddev composer lint`

#### Manual Verification:

- Loading a saved basket lands on `/koszyk` with exactly the saved products and quantities
- "Porównaj" after a load produces a verdict; the report describes the loaded basket
- No stale report renders directly after a load (the comparison flag is cleared by the same write)
- The replace warning appears on `/koszyki` only when the session basket is non-empty
- Loading a basket saved before a data refresh produces a verdict computed from the current
  leaflet windows — the point of FR-005
- The whole flow works on a phone-width viewport

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

Per CLAUDE.md, MVP testing is optional except the five promo mechanics — none of which this change
touches. Two tests are written anyway, both chosen because they cover a failure that is invisible
until it matters:

### Feature Tests:

- **`SavedBasketPrivacyTest`** (Phase 2) — cross-account read and delete are 404s; a guest is
  redirected to Google. Catches the regression where a route switches to implicit model binding and
  quietly resolves globally.
- **`SavedBasketLoadTest`** (Phase 3) — the save→load round trip preserves products and quantities
  exactly, and clamps a stored quantity that exceeds the current cap. Catches the regression where
  loading drops the quantity and every conditional mechanic then prices the wrong basket.

### Not covered by tests (manual only):

Name validation, the per-account cap, the empty-basket refusal, the replace warning, and the list
page's query count. All are visible immediately in the manual walkthrough below.

### Manual Testing Steps:

1. Sign in, build a basket on `/koszyk` with at least three products and one quantity above 1.
2. Save it with a name → lands on `/koszyki` with the basket listed, correct date and item count.
3. Try saving with a blank name, from an empty basket, and past the cap → Polish messages, nothing
   saved.
4. Clear the session basket, then load the saved one → `/koszyk` shows exactly what was saved.
5. Press "Porównaj" → a verdict for the loaded basket.
6. Add a product to the live basket, go to `/koszyki` → the replace warning appears; load anyway →
   the live basket is replaced.
7. Sign in as a second account → `/koszyki` is empty; the first account's basket id returns 404.
8. Delete a saved basket → gone; its items gone with it.
9. Repeat steps 2–5 at phone width.

## Performance Considerations

The list page eager-loads `items.product`, so it costs a bounded number of queries regardless of how
many baskets an account has saved — the per-account cap bounds the row count on top of that. The
load action resolves one basket with its items in one eager-loaded pass. Neither touches
`BasketComparator`, so the <2 s comparison budget is unaffected: re-comparing a loaded basket is
exactly the S-02 comparison path, unchanged.

## Migration Notes

Two forward-only migrations on tables that do not yet exist, so there is no existing data to migrate
and no rollback risk beyond dropping empty tables. Production MySQL has no managed backups (see
CLAUDE.md) — run `ddev artisan migrate` locally against a fresh database first, which
`migrate:fresh` in Phase 1 covers.

## References

- Roadmap item: `context/foundation/roadmap.md` → `S-03: Save and revisit a basket`
- Prior slice (deferred this schema here): `context/archive/2026-08-29-basket-builder-comparison-report/plan.md:44`
- Session basket and its invariants: `app/Basket/BasketSession.php:154`
- Pricing entry point and its input shape: `app/Pricing/BasketComparator.php:33`
- Ownership/foreign-key convention: `database/migrations/2026_08_29_100000_create_oauth_identities_table.php`
- Auth-gate test reasoning to mirror: `tests/Feature/Basket/BasketAccessTest.php`
- Factory parent rule, Blade `@php` rule: `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Schema, models and factories

#### Automated

- [x] 1.1 Migrations apply cleanly: `ddev artisan migrate:fresh` — 48fbde5
- [x] 1.2 Existing suite still passes: `ddev composer test` — 48fbde5
- [x] 1.3 Code style passes: `ddev composer lint` — 48fbde5

#### Manual

- [x] 1.4 SavedBasket factory with items round-trips; array-shape method matches `BasketSession::lines()` — 48fbde5
- [x] 1.5 User delete cascades to baskets and items; Product delete removes only referencing items — 48fbde5

### Phase 2: Save, list and delete — owner-scoped

#### Automated

- [x] 2.1 The privacy test passes: `ddev artisan test tests/Feature/Basket/SavedBasketPrivacyTest.php` — 34c1ac2
- [x] 2.2 Full suite passes: `ddev composer test` — 34c1ac2
- [x] 2.3 Code style passes: `ddev composer lint` — 34c1ac2

#### Manual

- [x] 2.4 Naming and saving a basket lands on `/koszyki` with it listed — 34c1ac2
- [x] 2.5 Blank name, empty basket and over-cap saves show Polish messages and save nothing — 34c1ac2
- [x] 2.6 List page shows name, date and item count with no N+1 — 34c1ac2
- [x] 2.7 Delete removes the basket and its items — 34c1ac2
- [x] 2.8 List page usable at phone width — 34c1ac2

### Phase 3: Load into the session and re-compare

#### Automated

- [x] 3.1 The load-fidelity test passes: `ddev artisan test tests/Feature/Basket/SavedBasketLoadTest.php`
- [x] 3.2 Full suite passes: `ddev composer test`
- [x] 3.3 Code style passes: `ddev composer lint`

#### Manual

- [x] 3.4 Loading a saved basket restores exactly the saved products and quantities
- [x] 3.5 "Porównaj" after a load produces a verdict describing the loaded basket
- [x] 3.6 No stale report renders directly after a load
- [x] 3.7 Replace warning appears only when the session basket is non-empty
- [x] 3.8 A basket saved before a refresh re-compares against current leaflet windows
- [x] 3.9 Whole flow works at phone width
