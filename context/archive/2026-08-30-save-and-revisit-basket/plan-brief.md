# Save and Revisit a Basket — Plan Brief

> Full plan: `context/changes/save-and-revisit-basket/plan.md`

## What & Why

A user who builds a basket today loses it to a logout, a cleared cookie or an expired session — the
basket lives only in the PHP session, which S-02 chose deliberately so that S-03 could own the
schema. This change gives baskets a home on the account: name and save one, find it again later,
and load it back to re-compare against whatever prices are current. It closes **FR-005** and
roadmap slice **S-03**.

## Starting Point

`BasketSession` (`app/Basket/BasketSession.php`) holds a slug-keyed quantity map in the session and
funnels every mutation through one private `store()` that also invalidates any comparison the user
is looking at. `BasketComparator::compare()` takes a plain `list<array{product, quantity}>` and
resolves prices through `PriceEntry::usableOn()` at call time. Auth is OAuth-only and working; the
`oauth_identities` table sets the ownership convention (`foreignId()->constrained()->cascadeOnDelete()`).
There are no basket tables, and no legacy shape to honour.

## Desired End State

A logged-in user saves the basket they built under a name, sees it on `/koszyki` with its save date
and item count, and comes back after the nightly refresh to load it and press "Porównaj" — getting a
verdict computed from today's data, not a stored one. Signed in as anyone else, that basket's URL is
a 404.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Load semantics | Replace the session basket | One basket concept and one editing surface, reusing `BasketSession`'s clamping, merging and comparison-invalidation with no second code path. |
| Item reference | `product_id` FK, cascade on delete | The database guarantees a saved basket never points at a product that does not exist, so the report can never render a phantom line. |
| Naming & limit | User-named, capped per account | Names are what make a list revisitable at all, and the cap bounds both the list and the per-account row count. |
| Save mode | Always a new save; delete from the list | One write path and no session-side "origin basket" pointer, so nothing can overwrite the wrong saved basket. |
| Duplicate names | Allowed, disambiguated by date | A unique `(user_id, name)` would reject the save-edit-save-again loop that "always a new save" implies. |
| Snapshot | Products and quantities only — never prices | FR-005 asks for re-comparison after a refresh; a stored verdict would be a stale answer wearing a fresh timestamp. |
| Denial code | 404, not 403 | A 403 confirms the basket exists, which is itself a leak across accounts. |
| Test scope | Privacy scoping + save→load fidelity | Covers the one load-bearing NFR plus the round trip whose failure silently corrupts every conditional promo calculation. |

## Scope

**In scope:** `saved_baskets` / `saved_basket_items` schema; `SavedBasket` / `SavedBasketItem`
models and factories; per-account cap and name bound in `config/koszykomat.php`; save form on
`/koszyk`; list page at `/koszyki` with delete; load action replacing the session basket; header
nav links; two feature tests; roadmap status sync.

**Out of scope:** price or verdict snapshots; update-in-place and renaming; sharing, public baskets
or export; JavaScript of any kind; pagination, search or sorting; any change under `app/Pricing/`;
guest saving; soft deletes.

## Architecture / Approach

Two tables hanging off `users`, with items pointing at canonical `products`. Ownership is enforced
by **scoping the query** — every lookup starts from `$request->user()->savedBaskets()`, so a foreign
id is simply not in the result set and `findOrFail()` yields a 404. Routes bind by id rather than by
implicit route-model binding, which resolves globally and would fetch another account's row before
any scoping ran. Loading converts the saved rows into the same `list<array{product, quantity}>` the
pricing engine already consumes and hands it to a new single-write method on `BasketSession`, so the
loaded lines and the cleared comparison flag land in one operation.

```
/koszyk  ──save──►  saved_baskets ──┬─► saved_basket_items ──► products
   ▲                                │
   └──────load (replace session)────┘        /koszyki = list + delete + load
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Schema, models, factories | Migrations, models, factories, config bounds | Cascade behaviour must be right the first time — MySQL 8.0 in production, SQLite in tests, so no MySQL-only DDL |
| 2. Save, list, delete | The write path and the ownership boundary | The privacy NFR — a scoping mistake leaks baskets across accounts |
| 3. Load + re-compare | Session replacement, load action, replace warning | The only phase touching existing code; a partial write could leave a stale verdict under a freshly loaded basket |

**Prerequisites:** S-02 (`basket-builder-comparison-report`) and F-02 (`oauth-authentication`) — both
archived and done. No external dependencies, no new packages.
**Estimated effort:** ~1–2 sessions across 3 phases; low technical risk, concentrated in Phase 2.

## Open Risks & Assumptions

- **A cascade-deleted product silently shrinks a saved basket.** Chosen deliberately over blocking
  catalogue maintenance, but the user gets no explanation of what vanished. Acceptable while the
  catalogue is a hand-seed; worth revisiting once S-04's nightly ingestion churns the product list.
- **The replace warning is advisory, not a gate.** With no JavaScript, a user can load over a
  non-empty basket in one click after reading the note. Losing an unsaved live basket is recoverable
  by rebuilding it; a JS confirm is not worth breaking S-02's no-JS constraint.
- **The per-account cap is a guess** (20). Nothing measured it; it is one config value to change.
- **Duplicate names are allowed**, so a careless user can end up with three "Zakupy" baskets
  distinguishable only by date.

## Success Criteria (Summary)

- A user saves a basket, leaves, returns, loads it, and gets a verdict computed from current prices —
  the round trip FR-005 describes.
- A saved basket is invisible and unreachable to every account but its owner (404, not 403).
- Loading restores exactly the products and quantities that were saved, with no stale verdict
  rendered beneath them.
