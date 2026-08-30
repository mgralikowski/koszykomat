# Leaflet Ingestion (Lidl PDF + Biedronka Vision) Implementation Plan

## Overview

Replace the hand-seeded price data with real leaflet data from both chains, through two parsers behind one driver interface — Lidl from its PDF text layer (exact, free), Biedronka from page images via a vision model. Every ingested row carries provenance, passes a deterministic validation gate before it can be trusted, and a row that fails the gate is stored as `needs_review` and can never reach a verdict.

This is roadmap item **F-03**. It is the change that turns a four-product demo into a product running on real prices, and it is also the change where the PRD's central guardrail — *"the verdict never lies"* — stops being a rendering rule and becomes a data-integrity rule.

## Current State Analysis

Both prerequisites are archived and the codebase is clean at `351dc86`. Full detail is in `context/changes/leaflet-vision-ingestion/research.md`; the load-bearing facts:

**The schema is ready to receive ingestion, with one gap.** `price_entries` carries `regular_price`, `promo_type`, and the three promo-parameter columns, with a unique index on `(leaflet_id, network_product_id, promo_type)` that is the natural upsert key. `leaflets` already has the `source_type` / `source_reference` hook. What is missing is per-row provenance: with Lidl exact and Biedronka model-read, two rows in the same table now have different trust levels, and nothing can express that.

**The promo contract enforces null-ness, not values.** `App\Enums\PromoType` says `one_plus_one` must carry `required_quantity` and `second_item_price` and must not carry `promo_price`. It does not say `required_quantity >= 2` or `promo_price < regular_price`. F-01's own review deferred exactly this here, by name: *"Value-level validation guards against mis-parsed rows … It becomes load-bearing when the vision pipeline writes entries."* Note also that `context/research/vision.md` §9 calls this API `PromoType::parameterContract()` — **no such method exists**; the real API is `requiredParameters()` / `forbiddenParameters()` / `parameterColumns()` / `isConditional()`.

**There is exactly one production read of priced data** — `app/Pricing/BasketComparator.php:64`, through `PriceEntry::validOn()`. Every other reference is test code.

**There is no queue worker.** Cron runs one `flock`-guarded `schedule:run` (`deploy/SERVER-SETUP.md:115-121`); `grep -rn 'queue:work' deploy/ .github/` returns nothing. A `dispatch()` in production would insert a `jobs` row and stop there, silently.

**`Money::fromDecimalString()` throws on anything non-numeric**, and `PromoCalculator` is built on a never-throw contract that holds today only because every value comes from a `decimal:2` column. S-01's review skipped this explicitly, *"revisit when F-03 ingestion starts feeding `Money` raw vision-API strings."*

**No bridge exists between a leaflet offer and the catalogue.** `network_products.product_id` is NOT NULL. `ExampleBasketSeeder` hand-binds "Mleko świeże Pilos 3,2%" (Lidl) and "Mleko świeże Łowicz 3,2%" (Biedronka) to the canonical `mleko-32-1l`. Ingestion produces raw leaflet names and has nothing that turns them into paired listings.

**`pdftotext` is not installed** — `.ddev/config.yaml:187` has `webimage_extra_packages` commented out, and adding binaries on the shared DirectAdmin box is human-approval territory (`infrastructure.md:85`).

## Desired End State

`ddev artisan leaflets:ingest` discovers the current leaflet for each chain, downloads it, parses it, and writes priced rows into `price_entries` for every canonical product declared in the pairing map — each row stamped with the driver that produced it, a confidence value, and a `needs_review` flag set by the validation gate.

The homepage and the basket report then price those products from real leaflet data instead of the seed, with no change to the pricing engine. A row that failed validation is invisible to the verdict: the basket says "brak danych" for that product rather than pricing it from a number nobody checked.

Accuracy of the Biedronka path is a measured fact, not a hope: a hand-labelled gold set and a decision rule written before the results were seen determine whether Biedronka ships, ships behind a mandatory gate, or stays on the seed while Lidl runs on real data.

### Key Discoveries:

- Upsert key already exists: unique `(leaflet_id, network_product_id, promo_type)` (`database/migrations/2026_07_25_120005_*`)
- The only verdict read path: `app/Pricing/BasketComparator.php:64`
- `PriceEntry::validOn()` is a `#[Scope]` delegating to `Leaflet::validOn()` via `whereHas`
- `Leaflet::validOn()` normalises with `startOfDay()` after F-01's F1 bug — passing a datetime where a date is expected is a live trap for ingestion computing windows from API dates
- `guzzlehttp/guzzle` is present transitively, so `Http::get()` needs no new dependency
- `config/services.php` is the established home for third-party credentials
- The Discover → Acquire → Parse split in `~/Projects/10x/docs/ARCHITECTURE.md` maps to PHP as interfaces + readonly DTOs + tagged DI services + a `first_success` loop
- The on-disk Biedronka corpus is 998×1624 **webp** from a May leaflet; the API serves 1146×1800 **PNG** today — the gold set must be built from current pages
- `infrastructure.md` lines 67, 72, 74, 92, 97–99 are a risk register written for this change

## What We're NOT Doing

- **No scheduler entry, no nightly automation.** The roadmap gives F-03 a manual CLI trigger; automatic nightly refresh is S-04's outcome. The command is built to be schedulable, but S-04 wires it.
- **No queue, no Job classes, no worker provisioning.** The command runs synchronously.
- **No automatic or heuristic product matching.** Pairing is declared in configuration; an offer that matches nothing is skipped, not guessed at.
- **No pairing UI or CLI management surface.** Admin tooling is a PRD non-goal.
- **No three-way model bake-off up front.** Gemini is measured against the decision rule; the other two candidates are escalation, not baseline.
- **No provenance surfaced in the user-facing report.** It would touch the component S-02 just shipped and the PRD does not require it; the columns exist for the gate and for audit.
- **No change to the four mandatory promo tests or to `PromoCalculator`'s arithmetic.**
- **No `PromoType` rename.** Lidl prints "Trzeci, najtańszy za grosz" and "8 w cenie 4", which `required_quantity` and the existing formula already handle; only the enum's *name* and Polish label imply n=2, and that is a labelling question for later.
- **No `docs/STACK.md` inheritance** — it is stale on eight points (`vision.md` §11).
- **No ingestion of Biedronka's 12 secondary leaflets** — the main food leaflet only.

## Implementation Approach

Three phases, ordered so the guardrail exists before any unverified number can be written.

Phase 1 builds the trust foundation with no network access at all: provenance columns, a validation gate that rejects rows whose values contradict their mechanic, and a single composed scope that the verdict reads through. It is fully verifiable against the existing seed.

Phase 2 builds the ingestion itself for both chains at once, behind the Discover → Acquire → Parse split. The command is a thin artisan entry point; all logic lives in services. Pairing comes from a curated configuration map, so a leaflet offer only ever becomes a priced row for a canonical product a human declared.

Phase 3 answers the one question no amount of code answers: whether a model reads Polish leaflet prices well enough to trust a verdict. It builds a hand-labelled gold set from current pages, measures Gemini against the decision rule from `vision.md` §10, and wires whichever branch the numbers select.

## Critical Implementation Details

**The `Money` boundary is where ingestion can break the never-throw contract.** `Money::fromDecimalString()` throws `InvalidArgumentException` on anything not `is_numeric`, and a model reading a Polish leaflet will produce `"19,99"`, `"19,99 zł"`, `"od 19,99"` or `""`. Normalisation belongs at the parser boundary and must return null rather than throw; nothing raw from a model or a PDF may reach `Money`.

**`Offer`, not `Product`, for the parsed DTO.** `ARCHITECTURE.md` names the parsed value object `Product`, which collides with the Eloquent model `App\Models\Product`. The DTO in this change is `Offer`.

**stderr is not failure for the PDF path.** `pdftotext` emits `Syntax Error: insufficient arguments for Marked Content` on the current Lidl leaflet while extracting all 15,559 words cleanly (`vision.md` §4). Whichever PDF path is used, a warning stream must not be read as an error.

**Date handling.** Chain APIs return `startDate` / `endDate` as datetimes or date strings; `leaflets.valid_from` / `valid_to` are date columns and `Leaflet::validOn()` had a real bug here (F-01 review F1). Normalise to dates on write.

---

## Phase 1: Trust foundation — provenance, validation gate, and one scope the verdict reads through

### Overview

Everything needed for an untrusted row to be harmless, built and verified before any such row can exist. No network, no external dependency, no new package.

### Changes Required:

#### 1. Provenance columns on `price_entries`

**File**: `database/migrations/<timestamp>_add_provenance_to_price_entries.php` (new)

**Intent**: Give each priced row its own trust level, because Lidl rows are exact text and Biedronka rows are a model's reading, and the two can sit in the same leaflet.

**Contract**: Adds `source` (string, nullable — the driver name, for audit), `confidence` (decimal, nullable — 1.0 for PDF text, model-derived otherwise), `needs_review` (boolean, NOT NULL, default `false`), `source_box` (json, nullable — the `box_2d` crop reference). Existing seeded rows keep `needs_review = false` and null provenance, so the seed stays usable. Add the columns to `PriceEntry`'s `#[Fillable]` and to `casts()` (`needs_review` → `boolean`, `confidence` → `decimal:2`, `source_box` → `array`). Must apply cleanly on MySQL 8 and on the in-memory SQLite the suite uses.

#### 2. Value-level promo invariants

**File**: `app/Enums/PromoType.php`

**Intent**: Close the gap F-01's review deferred here — the enum currently says which parameter columns must be present, not what values are admissible. A hallucinated `one_plus_one` with `required_quantity = 1` is structurally impossible and should be catchable without a database round-trip.

**Contract**: Add a method returning the value invariants per mechanic, alongside the existing `requiredParameters()` / `forbiddenParameters()`. The invariants are `vision.md` §9 rule 2: `one_plus_one` ⇒ `required_quantity >= 2` and `second_item_price == 0.00`; `second_for_fixed` ⇒ `required_quantity >= 2` and `0 < second_item_price < regular_price`; `simple` and `loyalty_card` ⇒ `promo_price < regular_price`; `none` ⇒ no constraint. Comparisons on money values go through `App\Pricing\Money`, never raw operators — the class docblock already states this contract.

#### 3. The validation gate

**File**: `app/Ingestion/Validation/PriceEntryGate.php` (new)

**Intent**: One place that decides whether a candidate row may be trusted, so no driver can write an unchecked number and no future caller can forget the check.

**Contract**: A service taking a candidate row (mechanic plus the four money/quantity values, plus the target `network_product_id`) and returning a verdict: trusted, or `needs_review` with a machine-readable reason. It applies, in order: the existing null-ness matrix from `PromoType`; the value invariants from change 2; and a cross-leaflet plausibility check — compare against the same `network_product`'s most recent previous `regular_price` and flag a swing beyond ±60% (`vision.md` §9 rule 4). A row with no previous price is not flagged on that ground. The gate never throws and never writes; it only judges.

#### 4. One composed scope the verdict reads through

**File**: `app/Models/PriceEntry.php`, `app/Pricing/BasketComparator.php`

**Intent**: Make trust structural in the same way S-01 made freshness structural — its docblock says an expired price *"has no code path into a total"*, and an unreviewed price must have none either. A caller must not be able to take freshness without trust.

**Contract**: Add `#[Scope] usableOn($date)` on `PriceEntry` composing the existing `validOn($date)` with `where('needs_review', false)`. `validOn()` remains as the freshness component — the four tests in `LeafletValidityScopeTest` and `PricePromoSeedTest` that assert its semantics stay valid — but its docblock states that verdicts read through `usableOn()`. `BasketComparator:64` switches its eager-load constraint from `validOn($on)` to `usableOn($on)`; nothing else in the comparator changes.

#### 5. Gate and guardrail tests

**File**: `tests/Feature/Ingestion/PriceEntryGateTest.php`, `tests/Feature/Pricing/BasketComparatorTrustTest.php` (both new)

**Intent**: Pin the two behaviours that would fail silently — a bad row being accepted, and a flagged row still reaching a verdict.

**Contract**: Gate tests cover one accepted row per mechanic and one rejection per invariant (including `one_plus_one` with `required_quantity = 1`, a `promo_price` above `regular_price`, a mechanic carrying a forbidden parameter, and a >60% price swing). The comparator test seeds a basket where one line's only valid entry is `needs_review = true` and asserts the verdict is "brak danych" naming that product, not a priced total.

### Success Criteria:

#### Automated Verification:

- Migration applies on MySQL: `ddev artisan migrate`
- Migration applies from scratch including SQLite: `ddev artisan migrate:fresh --seed` and `ddev composer test`
- Full test suite passes: `ddev composer test`
- New gate tests pass: `ddev artisan test tests/Feature/Ingestion/PriceEntryGateTest.php`
- Trust guardrail test passes: `ddev artisan test tests/Feature/Pricing/BasketComparatorTrustTest.php`
- Code style passes: `ddev composer lint`
- No production read of prices bypasses the composed scope: `grep -rn 'validOn' app/` shows no hit inside `app/Pricing/`

#### Manual Verification:

- `price_entries` carries the four new columns with `needs_review` NOT NULL default false
- The homepage still renders the seeded comparison unchanged — the seed's rows are all trusted
- Flipping one seeded row to `needs_review = true` in tinker makes the homepage say "brak danych" and name that product

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Ingestion for both chains

### Overview

The driver architecture and both parsers, writing real rows through the phase-1 gate. A thin artisan command over services; no queue, no scheduler.

### Changes Required:

#### 1. Dependencies

**File**: `composer.json`

**Intent**: Add the two libraries ingestion needs, both pure PHP — the poppler binary is not an option here (not in the ddev image, and a server change on a shared DirectAdmin box is human-approval territory).

**Contract**: Require `smalot/pdfparser` for the Lidl text layer and `prism-php/prism` for the vision call. `guzzlehttp/guzzle` is already present transitively; do not add it explicitly. `ddev composer check-platform-reqs` must still pass on PHP 8.5.

#### 2. Driver contracts and DTOs

**File**: `app/Ingestion/Contracts/{Discoverer,Acquirer,Parser}.php`, `app/Ingestion/{Flyer,Asset,Offer}.php` (new)

**Intent**: Adopt `ARCHITECTURE.md`'s three-stage split so a third chain is a configuration entry plus drivers, never a change to the core.

**Contract**: `Discoverer::discover(): list<Flyer>`; `Acquirer::canHandle(Flyer): bool` and `acquire(Flyer): list<Asset>`; `Parser::accepts(): list<string>` (asset kinds) and `parse(list<Asset>): list<Offer>`. DTOs are `final readonly` classes following the `app/Pricing` house style. `Flyer` carries chain slug, external id, title, validity window and a meta bag; `Asset` carries kind (`pdf`/`image`), local path, page number and source URL; `Offer` carries the raw leaflet name, the money values as **normalised strings or null**, the mechanic, page number, optional `box_2d`, `confidence` and `source`. The parsed DTO is named `Offer`, not `Product` — `App\Models\Product` already exists.

#### 3. Chain registry

**File**: `config/leaflets.php` (new), `app/Providers/AppServiceProvider.php`

**Intent**: Keep chain definitions and the pairing map out of code, in the shape the seeder already established, so adding a product is a configuration line.

**Contract**: `config/leaflets.php` declares, per chain slug: the discovery URL and API endpoints, the driver classes for each stage (as ordered lists, so a fallback can be appended later without touching the engine), and the **pairing map** — keyed by canonical product slug, then by chain slug, holding the match patterns for that chain's leaflet names plus the `brand` and `size_label` to record. The four seeded products are the initial entries, so ingestion immediately prices the existing example basket. Drivers are resolved from the container; register them in `AppServiceProvider`.

#### 4. Lidl driver

**File**: `app/Ingestion/Drivers/Lidl/{LidlHtmlDiscoverer,LidlApiPdfAcquirer,PdfTextParser}.php` (new)

**Intent**: Ingest the chain that needs no model — its PDF carries all four FR-007 mechanics as real text, so extraction is deterministic and `confidence` is 1.0.

**Contract**: Discovery scrapes flyer slugs from the leaflet listing page's static HTML; acquisition calls the flyer JSON endpoint for `pdfUrl`, `startDate`, `endDate` and page count, then downloads the PDF (~32 MB). `PdfTextParser` extracts text with `smalot/pdfparser` and derives offers from the phrase set verified in `vision.md` §4 — `cena poza promocją`, `przy zakupie <n>`, `gratis`, `za grosz`, `kupon`/`Lidl Plus`, `taniej`/`-NN%`. Every offer carries `source = 'lidl.pdf_text'` and `confidence = 1.0`. A warning on stderr is not a failure. Money values are normalised (comma → dot, currency and prefixes stripped) or set to null — never handed raw to `Money`.

#### 5. Biedronka driver

**File**: `app/Ingestion/Drivers/Biedronka/{BiedronkaHtmlDiscoverer,BiedronkaApiImageAcquirer,VisionParser}.php` (new)

**Intent**: Ingest the chain whose API returns images and nothing else — the only path to structure is a vision model.

**Contract**: Discovery scrapes leaflet anchors and the leaflet UUID from static HTML; acquisition calls the leaflet API for `images_desktop` and downloads the pages of the **main food leaflet only**. `VisionParser` sends one page per call through Prism to `gemini-3.5-flash-lite`, with a JSON response schema mirroring `Offer` so malformed output is a transport error rather than silent bad data, and requests `box_2d` per offer. Each offer carries `source = 'biedronka.vision.<model>'` and the model-derived confidence. Same normalisation rule as Lidl. The credential lives in `config/services.php` under `gemini`, with `GEMINI_API_KEY` added to `.env.example` next to the Google OAuth keys.

#### 6. The ingestion service and command

**File**: `app/Ingestion/LeafletIngestor.php` (new), `app/Console/Commands/IngestLeaflets.php` (new)

**Intent**: Run the pipeline for each configured chain and persist the result, with the command as a thin entry point so the logic is testable and reusable without an artisan invocation.

**Contract**: `LeafletIngestor` runs Discover → Acquire → Parse per chain via a `first_success` loop over the configured driver lists, maps each `Offer` onto a canonical product through the pairing map (skipping unmatched offers), `updateOrCreate`s the `Leaflet` on `(network_id, source_reference)` with `source_type` set to the driver, `updateOrCreate`s the `NetworkProduct` on the existing `(network_id, product_id)` unique key, runs every candidate row through `PriceEntryGate`, then `upsert`s `price_entries` on the existing `(leaflet_id, network_product_id, promo_type)` unique key with provenance and the gate's `needs_review` verdict. It returns a summary: offers parsed, matched, written, flagged, skipped.

`IngestLeaflets` registers as `leaflets:ingest`, accepts an optional chain filter and a `--dry-run` that parses and reports without writing, and prints the summary. It holds no logic of its own.

#### 7. Asset retention

**File**: `app/Ingestion/AssetStore.php` (new)

**Intent**: Keep two months of downloaded leaflets for re-parsing and audit, and make sure they cannot fill the disk MySQL also writes to — a named risk in `infrastructure.md`.

**Contract**: Assets land under `storage/app/leaflets/<chain>/<external-id>/`. After a successful run, prune directories whose leaflet's `valid_to` is older than two months. Steady state is roughly 1.5 GB (~32 MB Lidl PDF plus ~138 MB of Biedronka pages per week, held ~9 weeks). The store also reports free disk space so the command can warn below a configured threshold.

#### 8. Lidl parser fixture test

**File**: `tests/Feature/Ingestion/PdfTextParserTest.php`, `tests/Fixtures/Ingestion/lidl-page-sample.txt` (both new)

**Intent**: Prove the parser turns real leaflet text into correct offers, offline and without a 32 MB download.

**Contract**: The fixture is a verbatim extract of the current leaflet's text layer covering all four mechanics, including a `za grosz` and a `przy zakupie 2`. The test asserts the parsed offers' names, money values and mechanics, and that a malformed fragment yields no offer rather than an exception.

### Success Criteria:

#### Automated Verification:

- Platform requirements satisfied with the new packages: `ddev composer check-platform-reqs`
- Full test suite passes: `ddev composer test`
- Lidl parser fixture test passes: `ddev artisan test tests/Feature/Ingestion/PdfTextParserTest.php`
- Code style passes: `ddev composer lint`
- Command registered: `ddev artisan list | grep leaflets:ingest`
- Dry run completes without writing: `ddev artisan leaflets:ingest --dry-run` reports parsed and matched counts and leaves row counts unchanged

#### Manual Verification:

- A real `ddev artisan leaflets:ingest lidl` writes a leaflet and priced rows for the seeded products, with `source` and `confidence = 1.00`
- A real `ddev artisan leaflets:ingest biedronka` writes rows carrying a `box_2d` and a model-derived confidence
- Spot-check five ingested Lidl prices against the leaflet PDF — they match exactly
- The homepage renders the seeded example basket from ingested prices, with correct validity windows
- Re-running the command changes no row counts (idempotent upsert)
- Downloaded assets appear under `storage/app/leaflets/` and a leaflet older than two months is pruned
- An unmatched offer is skipped silently, not written as an orphan

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Gold set and the accuracy decision

### Overview

Answer the only question code cannot: whether the model reads Polish leaflet prices well enough for a verdict to be trusted. Measure against a rule written before the numbers are seen, then wire whichever branch the numbers select.

### Changes Required:

#### 1. The gold set

**File**: `tests/Fixtures/Ingestion/biedronka-gold-set/{labels.json,README.md}` (new)

**Intent**: Build the hand-labelled ground truth `vision.md` §10 calls for, in a form that outlives the measurement and stays small enough to version.

**Contract**: Five pages from the **current** Biedronka leaflet — not the May webp corpus in `~/Projects/10x/gazetki/`, which is the wrong format and resolution and would measure the model against inputs production never sees. Cover all four mechanics including one `za grosz`. `labels.json` holds every offer on those pages, hand-verified: name, regular price, promo price, mechanic and parameters. Page images are **not** committed (≈13 MB); `README.md` records the leaflet id, page numbers and the re-fetch command so the set can be reconstructed.

#### 2. Measurement command

**File**: `app/Console/Commands/MeasureVisionAccuracy.php` (new)

**Intent**: Score a model's output against the gold set on the three axes that matter differently, so the decision rule has real inputs.

**Contract**: `leaflets:measure-vision` runs the configured vision parser over the gold-set pages and scores against `labels.json`, reporting three figures separately: **price accuracy** (decides whether a verdict can be trusted), **mechanic classification accuracy** (the product wedge), and **offer recall** (a missed offer is survivable — it becomes "brak danych"; a wrong one is not). It accepts a model override so an escalation candidate can be scored without a config change. It writes nothing to `price_entries`.

#### 3. Apply the decision rule

**File**: `config/leaflets.php`, `context/changes/leaflet-vision-ingestion/measurement.md` (new)

**Intent**: Commit to the outcome the numbers select rather than to the outcome we hoped for, and record the evidence.

**Contract**: The rule, from `vision.md` §10, fixed before results are seen:

- **≥98% price and ≥95% mechanic** → adopt Gemini; Biedronka ships on real data.
- **90–98% price** → adopt with the phase-1 gate mandatory and an expected visible "brak danych" rate on Biedronka; record the rate.
- **<90% price** → escalate: score `claude-haiku-4.5` and `mistral-ocr-4.1` with the same command. If none clears 90%, set Biedronka's parser list to empty in `config/leaflets.php` so ingestion writes Lidl real data only and Biedronka stays on the seed. The product still works — the verdict says "brak danych" for Biedronka, which is what the guardrail is for.

`measurement.md` records the model, date, the three scores, which branch fired and the resulting configuration.

#### 4. Offer-mapping regression test

**File**: `tests/Feature/Ingestion/VisionOfferMappingTest.php`, `tests/Fixtures/Ingestion/biedronka-gold-set/model-response.json` (both new)

**Intent**: Turn the spike's artefact into the durable regression fixture §10 promises, without images and without network.

**Contract**: A captured raw model response for one gold-set page is replayed through the offer-mapping and validation path; the test asserts the resulting offers match `labels.json` for that page, and that a deliberately corrupted response yields flagged or skipped rows rather than an exception or a silently wrong price.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `ddev composer test`
- Offer-mapping regression test passes: `ddev artisan test tests/Feature/Ingestion/VisionOfferMappingTest.php`
- Code style passes: `ddev composer lint`
- Measurement command runs and reports all three scores: `ddev artisan leaflets:measure-vision`

#### Manual Verification:

- The gold set covers all four mechanics and every offer on its five pages is hand-verified
- The three scores are recorded in `measurement.md` with the model and date
- The branch that fired matches the rule as written — not adjusted after seeing the numbers
- If Biedronka ships: spot-check five ingested Biedronka prices against the leaflet images; they match
- If Biedronka does not ship: `leaflets:ingest` writes Lidl rows only, and the homepage shows "brak danych" for Biedronka without naming a winner

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

`CLAUDE.md` makes tests optional in the MVP outside the four promo mechanics, which S-01 already covers at engine level. This change adds tests only where a failure would be silent and would produce a wrong price.

### Unit Tests:

- `PriceEntryGate`: one accepted row per mechanic; one rejection per invariant — `one_plus_one` with `required_quantity = 1`, `promo_price` above `regular_price`, a forbidden parameter present, a >60% price swing
- Money normalisation: `"19,99"`, `"19,99 zł"`, `"od 19,99"`, `""` and `null` all resolve to a normalised string or null, never an exception

### Integration Tests:

- `BasketComparatorTrustTest`: a `needs_review` row yields "brak danych", never a priced total
- `PdfTextParserTest`: real Lidl leaflet text → correct offers for all four mechanics
- `VisionOfferMappingTest`: a captured model response → offers matching the gold set; a corrupted response → flagged or skipped, never a wrong price
- The existing `HomePageTest`, `BasketAccessTest` and the four promo tests must stay green throughout

### Manual Testing Steps:

1. `ddev artisan leaflets:ingest --dry-run` — parsed and matched counts look sane, nothing written.
2. `ddev artisan leaflets:ingest lidl` — spot-check five prices against the leaflet PDF.
3. Re-run the same command — row counts unchanged.
4. Flip one ingested row to `needs_review = true` — the homepage says "brak danych" for that product and names no winner.
5. `ddev artisan leaflets:ingest biedronka` — rows carry `box_2d`; spot-check five prices against the page images.
6. `ddev artisan leaflets:measure-vision` — record the three scores; confirm the branch that fires matches the written rule.
7. Confirm assets land under `storage/app/leaflets/` and that a leaflet with `valid_to` older than two months is pruned.

## Performance Considerations

Ingestion is a manual CLI run, off the request path, so the <2 s NFR is untouched. The verdict path gains one predicate (`needs_review = false`) on a query already bounded and eager-loaded; the query count on the basket page stays constant at eight regardless of basket size.

The run itself is the concern. A full Biedronka pass is ~53 sequential vision calls, and the Lidl PDF is a ~32 MB download. `infrastructure.md:67` narrates the failure this invites on a shared box: a hung job, cron firing the next run on top of it, PHP-FPM and MySQL saturated for every project on the machine. Two mitigations belong in this change even though scheduling does not: an explicit per-run time budget and a memory limit on the CLI path, and a lock so two manual runs cannot overlap. S-04 adds `withoutOverlapping()` at the scheduler level on top of that.

Disk is the second constraint: roughly 170 MB per weekly cycle held for two months, about 1.5 GB steady state, on the same disk MySQL writes to. The retention prune and the free-space warning are what keep that bounded.

## Migration Notes

One additive migration. `needs_review` defaults to `false`, so every existing seeded row stays trusted and the homepage is unaffected. `source`, `confidence` and `source_box` are nullable, so no backfill is required.

Rollback is a normal `down()` dropping the four columns — safe as long as `BasketComparator` is reverted with it, since `usableOn()` references `needs_review`.

Production has never been migrated with real data beyond the seed, and the F-02 production database reset is still outstanding and independent of this change (`context/archive/2026-08-29-oauth-authentication/plan.md` → Migration Notes).

## References

- Research: `context/changes/leaflet-vision-ingestion/research.md`
- Domain and vendor decision: `context/research/vision.md` — §4 (Lidl mechanics), §7 (volume and cost), §8 (vendor), §9 (validation gate), §10 (spike and decision rule)
- Driver architecture: `~/Projects/10x/docs/ARCHITECTURE.md`
- Operational risk register: `context/foundation/infrastructure.md` lines 67, 72, 74, 92, 97–99
- Deferred findings landing here: `context/archive/2026-07-25-price-promo-data-model-seed/reviews/impl-review.md` (F4), `context/archive/2026-07-25-guest-fixed-basket-comparison/reviews/impl-review.md` (F4)
- Upsert key: `database/migrations/2026_07_25_120005_create_price_entries_table.php`
- The only verdict read path: `app/Pricing/BasketComparator.php:64`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Trust foundation

#### Automated

- [x] 1.1 Migration applies on MySQL: `ddev artisan migrate` — 9fa1d32
- [x] 1.2 Migration applies from scratch including SQLite: `ddev artisan migrate:fresh --seed` and `ddev composer test` — 9fa1d32
- [x] 1.3 Full test suite passes: `ddev composer test` — 9fa1d32
- [x] 1.4 New gate tests pass: `ddev artisan test tests/Feature/Ingestion/PriceEntryGateTest.php` — 9fa1d32
- [x] 1.5 Trust guardrail test passes: `ddev artisan test tests/Feature/Pricing/BasketComparatorTrustTest.php` — 9fa1d32
- [x] 1.6 Code style passes: `ddev composer lint` — 9fa1d32
- [x] 1.7 No production read of prices bypasses the composed scope — no `validOn` hit inside `app/Pricing/` — 9fa1d32

#### Manual

- [x] 1.8 `price_entries` carries the four new columns with `needs_review` NOT NULL default false — 9fa1d32
- [x] 1.9 The homepage still renders the seeded comparison unchanged — 9fa1d32
- [x] 1.10 Flipping one seeded row to `needs_review = true` makes the homepage say "brak danych" and name that product — 9fa1d32

### Phase 2: Ingestion for both chains

#### Automated

- [x] 2.1 Platform requirements satisfied with the new packages: `ddev composer check-platform-reqs` — 88eef4d
- [x] 2.2 Full test suite passes: `ddev composer test` — 88eef4d
- [x] 2.3 Lidl parser fixture test passes: `ddev artisan test tests/Feature/Ingestion/PdfTextParserTest.php` — 88eef4d
- [x] 2.4 Code style passes: `ddev composer lint` — 88eef4d
- [x] 2.5 Command registered: `ddev artisan list | grep leaflets:ingest` — 88eef4d
- [x] 2.6 Dry run completes without writing and leaves row counts unchanged — 88eef4d

#### Manual

- [x] 2.7 A real Lidl ingest writes a leaflet and priced rows with `source` and `confidence = 1.00` — 88eef4d
- [x] 2.8 A real Biedronka ingest writes rows carrying `box_2d` and a model-derived confidence — 88eef4d
- [x] 2.9 Five ingested Lidl prices spot-checked against the leaflet PDF — they match exactly — 88eef4d
- [x] 2.10 The homepage renders the example basket from ingested prices with correct validity windows — 88eef4d
- [x] 2.11 Re-running the command changes no row counts (idempotent upsert) — 88eef4d
- [x] 2.12 Assets appear under `storage/app/leaflets/` and a leaflet older than two months is pruned — 88eef4d
- [x] 2.13 An unmatched offer is skipped silently, not written as an orphan — 88eef4d

### Phase 3: Gold set and the accuracy decision

#### Automated

- [x] 3.1 Full test suite passes: `ddev composer test` — 88eef4d
- [x] 3.2 Offer-mapping regression test passes: `ddev artisan test tests/Feature/Ingestion/VisionOfferMappingTest.php` — 88eef4d
- [x] 3.3 Code style passes: `ddev composer lint` — 88eef4d
- [x] 3.4 Measurement command runs and reports all three scores: `ddev artisan leaflets:measure-vision` — 88eef4d

#### Manual

- [x] 3.5 The gold set covers all four mechanics and every offer on its five pages is hand-verified — 88eef4d
- [x] 3.6 The three scores are recorded in `measurement.md` with the model and date — 88eef4d
- [x] 3.7 The branch that fired matches the rule as written, not adjusted after seeing the numbers — 88eef4d
- [x] 3.8 If Biedronka ships: five ingested Biedronka prices spot-checked against the page images — 4ac526c
- [x] 3.9 If Biedronka does not ship: ingest writes Lidl rows only and the homepage shows "brak danych" for Biedronka without naming a winner — 4ac526c
