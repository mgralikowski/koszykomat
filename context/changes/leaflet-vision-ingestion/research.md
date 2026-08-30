---
date: 2026-08-30T08:46:51+02:00
researcher: Marek Gralikowski
git_commit: 351dc8619e723856a4b729b4e9562c513b547906
branch: main
repository: koszykomat
topic: "Codebase grounding for F-03 leaflet ingestion, given context/research/vision.md"
tags: [research, codebase, leaflet-ingestion, price-entries, queue, scheduler, provenance]
status: complete
last_updated: 2026-08-30
last_updated_by: Marek Gralikowski
---

# Research: Codebase grounding for F-03 leaflet ingestion

**Date**: 2026-08-30T08:46:51+02:00
**Researcher**: Marek Gralikowski
**Git Commit**: `351dc8619e723856a4b729b4e9562c513b547906`
**Branch**: main
**Repository**: koszykomat

## Research Question

What does the codebase actually look like where F-03 has to land, given that
`context/research/vision.md` already settled the domain question (Lidl = PDF text layer,
Biedronka = vision model, no browser worker, under $2/month)?

`vision.md` is vendor and domain research: it answers *what to build and with what*. It does not
touch code. This document fills that gap: the schema ingestion writes into, the contract the
validation gate can actually lean on, where the verdict reads prices, and what execution
infrastructure exists on the server. Scope was extended by the user to cover the out-of-repo prior
art and the verdict-query surface that `needs_review` depends on.

## Summary

Six findings change the shape of F-03's plan. Two are corrections to `vision.md`, three are
constraints the codebase imposes that no document currently records, and one is a landmine two
archived reviews already saw coming.

1. **`PromoType::parameterContract()` does not exist.** `vision.md` §9 names it as "already shipped
   in F-01" and builds validation rule 1 on it. The capability is real but the API is
   `requiredParameters()` / `forbiddenParameters()` / `parameterColumns()` / `isConditional()`.
   The plan must not cite a method that isn't there.
2. **There is no queue worker in production.** Cron runs `schedule:run` only. Nothing consumes the
   `jobs` table. A dispatched job would be written and never executed — silently. This is the single
   most consequential finding for a "queued job" foundation.
3. **The verdict reads prices from exactly one place in production** —
   `app/Pricing/BasketComparator.php:64`. Every other hit is test code. Wiring `needs_review` into
   the guardrail is a one-line insertion, not a refactor.
4. **`Money::fromDecimalString()` throws on anything non-numeric**, and Polish decimals use commas.
   S-01's review skipped this explicitly *"revisit when F-03 ingestion starts feeding `Money` raw
   vision-API strings."* That day is this change.
5. **`pdftotext` is not available** — not in the ddev image, and adding system binaries on the
   shared DirectAdmin box is human-approval territory. This tips `vision.md`'s "smalot/pdfparser
   (or the poppler binary)" decisively toward the pure-PHP library.
6. **The on-disk Biedronka corpus is the wrong input for the gold set** — 6 pages of 998×1624
   **webp** from a May leaflet, while the API serves 1146×1800 **PNG** today. Building the §10 gold
   set on it would measure the model against inputs the pipeline will never see.

`ARCHITECTURE.md`'s Discover → Acquire → Parse split maps cleanly onto Laravel and is worth
adopting. F-01's schema is ready to receive ingestion with one addition (the provenance columns),
and its unique index already gives idempotency a natural key.

## Detailed Findings

### 1. F-01's schema — what ingestion writes into

Five tables, all shipped and archived (`context/archive/2026-07-25-price-promo-data-model-seed/`).

`price_entries` (`database/migrations/2026_07_25_120005_create_price_entries_table.php`):

| Column | Type | Note |
| --- | --- | --- |
| `leaflet_id` | FK cascade | NOT NULL — every price belongs to a leaflet |
| `network_product_id` | FK cascade | |
| `regular_price` | `decimal(8,2)` NOT NULL | always present, so overbuy cost stays computable |
| `promo_type` | string, default `'none'` | cast to `App\Enums\PromoType` |
| `promo_price` | `decimal(8,2)` nullable | |
| `required_quantity` | `unsignedTinyInteger` nullable | max 255 — accommodates n=3 and "8 w cenie 4" |
| `second_item_price` | `decimal(8,2)` nullable | |

**The unique index is `(leaflet_id, network_product_id, promo_type)`** — this is the natural
`upsert()` key for idempotent re-ingestion, and it deliberately allows one listing to carry both a
regular and a loyalty-card price in the same leaflet.

`leaflets` already carries the ingestion hook F-03 needs at leaflet level: `source_type` (string,
default `'manual'`) and `source_reference` (string, nullable), plus an index on
`(network_id, valid_from, valid_to)`.

**Confirmed: `price_entries` has no provenance columns.** `vision.md` §9's claim is accurate —
`source`, `confidence`, `needs_review` and `source_box` all have to be added by F-03.

### 2. The promo-parameter contract — correcting `vision.md` §9

`app/Enums/PromoType.php` exposes:

- `requiredParameters(): list<string>` — columns that must hold a value for the mechanic
- `forbiddenParameters(): list<string>` — derived as the complement over `parameterColumns()`
- `parameterColumns(): list<string>` — `['promo_price', 'required_quantity', 'second_item_price']`
- `isConditional(): bool` — true when `required_quantity` is required
- `label(): string` — Polish, user-facing

There is **no `parameterContract()`**. `vision.md` §9 rule 1 names it and describes it correctly in
substance ("reject any row whose parameters contradict its mechanic") — only the method name is
invented. The enum's own docblock states why the matrix lives in PHP rather than DDL: MySQL 8 and
the in-memory SQLite used by tests disagree on check constraints.

**More importantly, the existing contract is null-ness only.** It says `one_plus_one` must have
`required_quantity` and `second_item_price` and must not have `promo_price`. It does **not** say
`required_quantity >= 2`, or `second_item_price = 0.00`, or `promo_price < regular_price`. Those are
`vision.md` §9's rule 2, and they do not exist yet. F-01's own review saw this coming and deferred
it here by name (see Historical Context).

### 3. The verdict read path — one insertion point

Grepping `validOn|priceEntries` across `app/`, `database/` and `tests/` returns exactly one
production read of priced data:

```php
// app/Pricing/BasketComparator.php:64
'networkProducts.priceEntries' => fn ($query) => $query->validOn($on)->with('leaflet'),
```

Everything else is `tests/Feature/Database/*` asserting freshness semantics. `PriceEntry::validOn()`
is a `#[Scope]` that defers to `Leaflet::validOn()` via `whereHas`.

This makes the `needs_review` exclusion cheap, and it raises a design question worth deciding in the
plan rather than in code. S-01 deliberately made freshness *structural* — its own docblock says
*"entries are fetched through PriceEntry::validOn(), so an expired price has no code path into a
total. That makes the 'never a stale verdict' guarantee structural rather than something every
caller has to remember."* Trust should get the same treatment. Three options:

- fold the exclusion into `validOn()` — cheapest, but overloads a scope whose name promises one
  thing and whose semantics four tests assert;
- add a separate `trusted()` scope and chain both at the call site — clean separation, but a future
  caller can take freshness without trust;
- add one composed scope (e.g. `usableOn($date)` = valid **and** trusted) and have the comparator
  call only that — keeps the guarantee indivisible, which is the property S-01 was after.

### 4. Execution infrastructure — the scheduler is wired, the queue is not

`routes/console.php` contains only the stock `inspire` command. There are no scheduled tasks.

`deploy/SERVER-SETUP.md:115-121` provisions exactly one cron entry, through DirectAdmin's own cron
UI so a panel rebuild does not wipe it, `flock`-guarded per the risk register:

```
* * * * * flock -n /tmp/koszykomat-sched.lock /usr/local/php85/bin/php .../artisan schedule:run
```

The file says outright: *"The scheduler is empty until feature work adds the nightly ingestion — the
entry is wired now so the pipe is live."* That pipe is F-03's.

**But `grep -rn 'queue:work' deploy/ .github/` returns nothing.** `config/queue.php` defaults to the
`database` driver and the `jobs` table exists, and `deploy/release.sh:59` signals workers to restart
— but no process is provisioned to consume the queue. A `dispatch()` in production would insert a
row into `jobs` and stop there, with no error anywhere. `infrastructure.md:74` anticipates this and
names the remedy as a pattern rather than a fact: *"classic DirectAdmin boxes often lack process
supervision; the cron-only pattern (`queue:work --stop-when-empty` per minute) works but long
vision-API jobs need explicit `--timeout`, lock discipline, and overlap protection."*

So F-03 must choose deliberately: run ingestion synchronously inside a scheduled command, or
provision the cron-only worker pattern. Either way `withoutOverlapping()` is mandatory —
`infrastructure.md:67` narrates the exact failure (a hung nightly job, cron firing the next
`schedule:run` on top of it, two workers saturating PHP-FPM and MySQL until unrelated projects on the
shared box stopped responding).

### 5. PDF tooling — the choice is made for us

`guzzlehttp/guzzle` is present transitively via `laravel/framework`, so `Http::get()` works with no
new dependency. Nothing else ingestion needs is installed: no `smalot/pdfparser`, no `prism-php/prism`.

`pdftotext` is **not** available: `.ddev/config.yaml:187` has `webimage_extra_packages` commented
out, so poppler is not in the local image, and on the production side `infrastructure.md:85` puts
anything touching the DirectAdmin panel behind human-only approval. `vision.md` §6 offers
*"`smalot/pdfparser` (or the `poppler` binary)"* as equivalent; in this environment they are not.
A pure-PHP parser is a composer line; the binary is a server change on a shared box.

Note that `vision.md` §4 flags a caveat that survives either choice: `pdftotext` emits
`Syntax Error: insufficient arguments for Marked Content` on the current leaflet while extracting all
15,559 words cleanly — **the parser must not treat stderr output as failure.**

`config/services.php` is the established home for third-party credentials (`google` sits there for
Socialite, with a comment explaining the derived redirect). A `gemini` key belongs there, with
`GEMINI_API_KEY` added to `.env.example` alongside `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.

### 6. `Money`'s never-throw contract has a hole that ingestion opens

```php
// app/Pricing/Money.php
public static function fromDecimalString(string $amount): self
{
    if (! is_numeric($amount)) {
        throw new InvalidArgumentException("Not a numeric money amount: [{$amount}].");
    }
    return new self(bcadd($amount, '0', self::SCALE));
}
```

`PromoCalculator` is built on a never-throw contract — its docblock says a malformed row "must make
a line unpriceable (which surfaces as 'brak danych'), never throw and never guess a number." That
holds today only because every value reaching `Money` comes from a `decimal:2` column.

A vision model reading a Polish leaflet will produce `"19,99"`, `"19,99 zł"`, `"od 19,99"` or `""`.
None is `is_numeric`. If any such string reaches `Money` before normalisation, the guardrail flips
from "brak danych" to a 500. F-03 must normalise and validate at the parser boundary, and never hand
a raw model string to `Money`.

### 7. Prior art — architecture worth adopting, corpus worth discarding

`~/Projects/10x/docs/ARCHITECTURE.md` (2026-06-02) holds up, as `vision.md` §11 claims. Its
three-stage split is the right skeleton:

```
Discover ───► Acquire ───► Parse
(which        (fetch        (content →
 leaflets)     content)      products)
```

Per-shop config lists drivers per stage with fallback, resolved by a `first_success` loop; it
already assigns `Lidl: Acquire=API(PDF), Parse=PdfText` and `Biedronka: Acquire=API(images),
Parse=Vision`, and records `TesseractParser` as consciously rejected. It explicitly maps to PHP as
"interface + readonly DTO, registry via tagged DI services in the container, fallback as the same
`first_success` loop" — directly usable.

Its `Product` value object carries `confidence: float` (*"1.0 = z danych strukturalnych, <1 =
OCR/vision"*), `source: str` (*"nazwa drivera, dla audytu"*) and `page_no` — the provenance F-01's
schema lacks, anticipated three months early. One discrepancy to resolve during implementation:
`ARCHITECTURE.md` names the Lidl asset `hiResPdfUrl`, `vision.md` §3 verified `pdfUrl`.

**The corpus is a different story.** `~/Projects/10x/gazetki/biedronka/` holds 6 pages at
**998×1624 webp** from a leaflet dated *od 23-05* — three months stale, and neither the format nor
the resolution the API serves today (`vision.md` §3: 1146×1800 PNG, ~2.6 MB). `vision.md` §5 already
made this argument against the Tesseract test — that it ran on images at "about half the source
resolution" — and the same objection applies here. The §10 gold set is meant to outlive the spike as
the ingestion regression fixture; building it on June's webp would pin the fixture to inputs
production never sees. **Download 5 current pages instead.** The Lidl side of the corpus (110 pages
plus Tesseract outputs) is useful only as evidence for why Tesseract was rejected.

### 8. Two archived findings land squarely on this change

Both were deferred here by name, and neither appears in `vision.md`.

**F-01 review, F4 — "Promo-parameter contract enforces null-ness but not the plan's pinned values."**
Decision: *"DEFERRED to F-03. Value-level validation guards against mis-parsed rows, and every row
today is hand-written in the seeder. It becomes load-bearing when the vision pipeline writes
entries."* This is `vision.md` §9's rule 2, identified independently a month earlier. The two
documents agree; the plan should treat it as a settled requirement rather than a new idea.

**S-01 review, F4 — "`Money::fromDecimalString` can throw, breaking the never-throw contract."**
Decision: *"SKIPPED — unreachable from `decimal:2` columns today; revisit when F-03 ingestion starts
feeding `Money` raw vision-API strings."* See finding 6.

Also relevant, from F-01's review: `Leaflet::validOn()` once returned nothing on a leaflet's last
valid day when handed a datetime string (F1, fixed in `fbef5d7`, regression test in
`LeafletValidityScopeTest`). Ingestion computes validity windows from API dates; passing a datetime
where a date is expected is the same trap.

## Code References

- `database/migrations/2026_07_25_120005_create_price_entries_table.php` — target table; unique
  `(leaflet_id, network_product_id, promo_type)` is the upsert key
- `database/migrations/2026_07_25_120004_create_leaflets_table.php` — `source_type` /
  `source_reference` hook, already present
- `app/Enums/PromoType.php:63-96` — `requiredParameters()`, `forbiddenParameters()`,
  `isConditional()`, `parameterColumns()`; the real API behind `vision.md` §9 rule 1
- `app/Models/PriceEntry.php` — `decimal:2` casts, `#[Scope] validOn()` via `whereHas`
- `app/Models/Leaflet.php` — `validOn()` with the `startOfDay()` normalisation from F-01's F1 fix
- `app/Pricing/BasketComparator.php:64` — the only production read of priced data
- `app/Pricing/Money.php` — `fromDecimalString()` throws `InvalidArgumentException` on non-numeric
- `app/Pricing/PromoCalculator.php` — the never-throw contract ingestion must not break
- `routes/console.php` — no scheduled tasks yet
- `deploy/SERVER-SETUP.md:115-121` — the single `flock`-guarded `schedule:run` cron entry
- `deploy/release.sh:59` — `queue:restart` signal with no worker provisioned
- `config/services.php` — credential convention (`google`); where a `gemini` key belongs
- `.ddev/config.yaml:187` — `webimage_extra_packages` commented out; no poppler

## Architecture Insights

- **Guarantees in this codebase are made structural, not procedural.** S-01 enforced freshness at
  load time so no caller can forget it; F-01 put the promo-parameter matrix in the enum because DDL
  could not carry it portably; S-02 put quantity clamping inside `BasketSession` so no controller
  could skip it. `needs_review` should follow the same pattern — a single scope the comparator
  cannot take half of.
- **The unique index is the idempotency contract.** Re-ingesting the same leaflet must `upsert()` on
  `(leaflet_id, network_product_id, promo_type)`, not delete-and-insert; the seeder already
  establishes `updateOrCreate` as the house idiom.
- **Trust is now per-row, not per-source.** F-01 modelled provenance at leaflet level
  (`source_type`), which was adequate while every row was hand-seeded. With Lidl exact and Biedronka
  model-read, two rows in the same leaflet can have different trust levels — which is what forces
  provenance down to `price_entries`.
- **`ARCHITECTURE.md`'s three-stage split maps onto Laravel without strain**: three interfaces,
  per-chain driver lists resolved from config, tagged services in the container, and a
  `first_success` loop. It is the right skeleton and needs no reinvention.
- **The infrastructure risk register was written for this change.** `infrastructure.md` lines 67,
  72, 74, 92, 97, 98 and 99 all describe leaflet-ingestion failure modes on a shared box:
  overlapping cron runs, workers without supervision, leaflet images filling the disk MySQL writes
  to, a dead refresh cron silently serving stale prices. The plan should read as an answer to that
  register.

## Historical Context (from prior changes)

- `context/archive/2026-07-25-price-promo-data-model-seed/reviews/impl-review.md` — F4 deferred
  value-level promo validation to F-03 by name; F1 documents the `validOn()` datetime trap; F3
  records that factories must derive the listing from the leaflet's network
- `context/archive/2026-07-25-guest-fixed-basket-comparison/reviews/impl-review.md` — F4 deferred
  the `Money` throw-on-garbage question to F-03 by name; F1 added a promo-above-regular clamp, which
  is a validation invariant ingestion can now violate at scale
- `context/archive/2026-07-25-price-promo-data-model-seed/plan.md` — why the promo matrix lives in
  PHP rather than DDL
- `context/archive/2026-08-29-basket-builder-comparison-report/plan.md` — the `<x-comparison-report>`
  component is where per-row provenance would surface to the user, extending FR-008 transparency

## Related Research

- `context/research/vision.md` — the domain and vendor decision this change implements; §9
  (validation gate), §10 (phase-1 spike and decision rule) and §13 (roadmap impact) are the
  load-bearing sections
- `~/Projects/10x/docs/ARCHITECTURE.md` — driver architecture, adopt
- `~/Projects/10x/docs/RESEARCH.md` — endpoint recon, superseded by `vision.md` §3
- `~/Projects/10x/docs/STACK.md` — stale on eight points, do not use (`vision.md` §11)

## Open Questions

1. **Synchronous scheduled command, or provision a queue worker?** No worker exists; both paths are
   defensible and the choice shapes the whole job design. A synchronous command avoids provisioning
   entirely but must survive a ~53-page vision run inside one cron invocation.
2. **Where does the `needs_review` exclusion live** — inside `validOn()`, in a sibling `trusted()`
   scope, or in one composed scope the comparator calls? Finding 3 lays out the trade-off; the
   plan should pick one.
3. **Does the report surface provenance to the user?** §9 argues it extends FR-008's transparency
   from pairing to provenance, but the PRD does not require it and it would touch a component S-02
   just shipped. Scope call for the plan.
4. **`SecondForFixed` is named for n=2 but Lidl prints "Trzeci, najtańszy za grosz" and "8 w cenie
   4".** `required_quantity` already accommodates it and `PromoCalculator`'s formula is general, so
   this is a naming and labelling question (`PromoType::label()` returns "drugi produkt za"), not a
   schema one. `vision.md` §4 flags it; confirmed here that no schema change is needed.
5. **Where do downloaded assets live, and who rotates them?** `infrastructure.md` names a full disk
   as a live risk on the box MySQL also writes to, and nothing in the codebase touches storage yet.
