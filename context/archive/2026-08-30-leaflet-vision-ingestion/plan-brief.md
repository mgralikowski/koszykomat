# Leaflet Ingestion (Lidl PDF + Biedronka Vision) — Plan Brief

> Full plan: `context/changes/leaflet-vision-ingestion/plan.md`
> Research: `context/changes/leaflet-vision-ingestion/research.md`
> Domain input: `context/research/vision.md`

## What & Why

Replace the hand-seeded prices with real leaflet data from both chains — Lidl parsed from its PDF text layer (exact, free), Biedronka read from page images by a vision model. This is roadmap item **F-03**, and it is the change that turns a four-product demo into a product running on real prices. It is also where the PRD's central guardrail — *"the verdict never lies"* — stops being a rendering rule and becomes a data-integrity rule: every ingested row carries provenance, passes a deterministic gate, and a row that fails is invisible to the verdict.

## Starting Point

F-01's schema is ready to receive ingestion and already has the right upsert key, but it models provenance only at leaflet level — adequate while every row was hand-written, wrong now that Lidl rows are exact text and Biedronka rows are a model's reading. `PromoType` enforces which promo parameters must be present, not what values are admissible; F-01's review deferred that gap here by name. There is exactly one production read of priced data (`BasketComparator.php:64`), no queue worker on the server, and no bridge at all between a leaflet offer and the canonical catalogue — `network_products.product_id` is NOT NULL and the seeder binds every pair by hand.

## Desired End State

`ddev artisan leaflets:ingest` discovers each chain's current leaflet, downloads it, parses it, and writes priced rows for every canonical product declared in a curated pairing map — each row stamped with its driver, a confidence value and a gate verdict. The homepage and basket report price those products from real data with no change to the pricing engine. Accuracy of the Biedronka path is a measured fact recorded against a decision rule written before the numbers were seen, not a hope.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Vendor split | Lidl PDF text, Biedronka Gemini 3.5 Flash-Lite | Half the corpus is exact by construction, so only one chain carries extraction risk. | Research (`vision.md`) |
| Product matching | Curated pairing map in `config/leaflets.php` | Pairing is explicit by construction, honouring the PRD's "simple equivalents only" non-goal; no heuristic can produce a false pair. | Plan |
| Chain sequencing | Both chains in the same phase | Shorter path to a real Lidl-vs-Biedronka comparison; accepted trade is two variables moving at once. | Plan |
| Execution | Synchronous artisan command, logic in services | No queue worker exists on the server, so a dispatched job would be written and never run; the command stays thin and the logic testable. | Plan |
| Trust in the verdict | One composed scope `usableOn()` | Matches how this codebase makes guarantees structural — no caller can take freshness without trust. | Plan |
| Spike scope | Gold set + measure Gemini; escalate only on failure | The durable artefact gets built either way, without paying for two runs whose result probably changes nothing. | Plan |
| Asset retention | Keep two months, then prune | Re-parsing and audit stay possible while the disk MySQL shares stays bounded at ~1.5 GB. | Plan |
| Test depth | Gate tests + parser fixtures, offline | Covers exactly what can lie silently about a price; turns the spike artefact into a regression net. | Plan |
| Scheduling | Out of scope | The roadmap gives F-03 a manual CLI trigger; nightly refresh is S-04's outcome. | Plan |

## Scope

**In scope:** provenance columns on `price_entries`; value-level promo invariants; `PriceEntryGate`; composed `usableOn()` scope and the comparator switch; Discover/Acquire/Parse contracts and DTOs; Lidl PDF driver; Biedronka vision driver via Prism; `config/leaflets.php` with the curated pairing map; `LeafletIngestor` + thin `leaflets:ingest` command; two-month asset retention with a free-space warning; gold set, `leaflets:measure-vision`, and the decision rule; gate, parser-fixture and offer-mapping tests.

**Out of scope:** scheduler and nightly automation (S-04); queues, Job classes, worker provisioning; automatic or heuristic product matching; any pairing UI or CLI management surface; three-way model bake-off up front; provenance surfaced in the user-facing report; changes to `PromoCalculator` or the four mandatory promo tests; renaming `SecondForFixed`; Biedronka's 12 secondary leaflets.

## Architecture / Approach

Three interfaces — `Discoverer`, `Acquirer`, `Parser` — with per-chain ordered driver lists resolved from `config/leaflets.php` through a `first_success` loop, lifted from `ARCHITECTURE.md` and mapped onto tagged container services. `LeafletIngestor` runs the three stages per chain, maps each parsed `Offer` onto a canonical product through the pairing map (skipping anything unmatched), runs every candidate through `PriceEntryGate`, and upserts `price_entries` on the key F-01 already defined. `IngestLeaflets` is a thin artisan entry point over that service — no queue, no Job classes, since no worker exists to consume one. On the read side, `BasketComparator` switches from `validOn()` to a composed `usableOn()` that is freshness **and** trust, so an unreviewed row has no code path into a total.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Trust foundation | Provenance columns, value invariants, `PriceEntryGate`, `usableOn()` scope, gate + guardrail tests | Touching the single read path every verdict depends on; mitigated by an additive migration and a default that leaves the seed trusted |
| 2. Ingestion for both chains | Driver contracts, both parsers, pairing map, `leaflets:ingest`, asset retention, Lidl fixture test | Real rows land before accuracy is measured — safe only because phase 1's gate already exists; and two chains move at once, so a failure has two candidate causes |
| 3. Gold set + accuracy decision | Hand-labelled gold set, `leaflets:measure-vision`, the decision rule applied and recorded, offer-mapping regression test | The temptation to adjust the threshold after seeing the numbers; the rule is written down first for exactly that reason |

**Prerequisites:** F-01 and F-02 archived (they are). A Gemini API key in `.env`. Network access to both chains' public endpoints. Two new composer packages (`smalot/pdfparser`, `prism-php/prism`).
**Estimated effort:** ~3–4 sessions across 3 phases; phase 2 is by far the largest.

## Open Risks & Assumptions

- **Two chains move at once.** Chosen deliberately over a Lidl-first sequence for a shorter path to a real comparison; the cost is that a bad result in phase 2 has two candidate causes — the pipeline core or the vision model.
- **The catalogue only grows as fast as the pairing map is written.** Curated pairing is what keeps the verdict honest, but it means the "four products" problem that motivated picking F-03 over S-03 is solved only to the extent the map is filled in by hand.
- **Chain endpoints are undocumented and unversioned.** Everything was verified live on 2026-08-29; a layout change on either site breaks discovery with no deprecation notice. Fixture tests will not catch that — only a real run will.
- **`Money` throws on non-numeric input.** A model returning `"19,99 zł"` would break `PromoCalculator`'s never-throw contract; normalisation at the parser boundary is what prevents it, and it is easy to bypass by accident.
- **A ~53-call vision run in one CLI invocation on a shared box** is precisely the shape `infrastructure.md` warns about. Lock discipline and an explicit time budget belong here even though the scheduler does not.
- **Vision accuracy is genuinely unknown** on Polish leaflets — no benchmark measures this. The decision rule exists so the answer is actionable either way, including the branch where Biedronka stays on the seed.

## Success Criteria (Summary)

- The homepage and basket report price the seeded products from real leaflet data, with correct validity windows and no change to the pricing engine.
- A row the gate flags never reaches a verdict — the basket says "brak danych" and names the product instead.
- Biedronka's price accuracy is a recorded number measured against a hand-labelled gold set, and the branch that fired matches the rule as written.
