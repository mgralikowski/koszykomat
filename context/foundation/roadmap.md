---
project: Koszykomat
version: 1
status: draft
created: 2026-07-21
updated: 2026-08-30
prd_version: 1
main_goal: market-feedback
top_blocker: none
milestone_id: mvp-promo-aware-basket-verdict
milestone_seq: 1
milestone_status: open
---

# Roadmap: Koszykomat

> Derived from `context/foundation/prd.md` (v1) + `tech-stack.md` / `infrastructure.md` / `deploy-plan.md` + auto-researched codebase baseline.
> Edit-in-place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Milestone

**M-01: MVP — promo-aware basket verdict** — Status: open

- **Intent:** prove and ship the product wedge end-to-end — a basket verdict that understands the five promo mechanics, prices the forced overbuy, and either names an honest winner or says "no data" — first on a hand-seeded dataset, then on real nightly-refreshed leaflet data.
- **Source materials:** `context/foundation/prd.md` (v1) + `tech-stack.md` / `infrastructure.md` / `deploy-plan.md` + auto-researched codebase baseline.
- **Done when:** every F-NN and S-NN below is `done`.
- **Scope anchors:** FR-001 – FR-009, US-01; NFR mobile-first, NFR <2 s responsiveness, NFR data-freshness transparency, NFR basket privacy; Business Logic; Access Control.

> Adopted `2026-08-29` — this roadmap predates the milestone layer and was wrapped as `M-01` in place, with slices and the dependency graph preserved verbatim and item statuses re-derived from what was on disk. Statuses have moved since; the `## At a glance` table is authoritative, not this note.
>
> Refreshed `2026-08-29` against `context/research/vision.md` §13: the vision-API vendor question is resolved per-chain, so `F-03` and `S-04` are no longer blocked and `top_blocker` is no longer `external`.

## Vision recap

Shoppers at Polish discount chains cannot tell whether their basket is actually cheaper at Lidl or Biedronka — real prices are trapped in weekly leaflets (images/PDFs) in a form that cannot be compared 1:1, and the two chains' advertising contradicts each other. Koszykomat structures leaflet prices into a comparable form and computes a promo-aware "where is it cheaper" verdict for the whole basket, honestly pricing conditional mechanics (1+1, second-for-1-PLN) including their hidden cost — a forced multi-item purchase.

The product wedge — the one trait that, if removed, makes this indistinguishable from a generic leaflet-aggregator — is the **promo-aware basket verdict**: it doesn't just show prices, it understands the five promo mechanics and prices the forced overbuy, then names an honest winner (or says "no data"). Everything else on this roadmap exists to make that verdict real, trustworthy, and reachable.

## North star

**S-01: A guest sees a fixed example-basket comparison with a verdict on the homepage.** — This is the validation milestone: the smallest end-to-end flow whose successful delivery proves the core product hypothesis (a promo-aware verdict is correct and worth trusting), placed first because everything else only matters if this works.

> "North star" here means the smallest end-to-end slice that proves the core hypothesis — placed as early as its Prerequisites allow. S-01 needs only a minimal data model + a hand-seed (F-01); it deliberately does not require auth or live leaflet ingestion, so it proved the wedge at the lowest cost while the leaflet-ingestion question was still open. Delivered — see `## Done`.

## At a glance

| ID   | Change ID                        | Outcome (user can …)                                              | Prerequisites | PRD refs                          | Status   |
| ---- | -------------------------------- | ----------------------------------------------------------------- | ------------- | --------------------------------- | -------- |
| F-01 | price-promo-data-model-seed      | (foundation) price/promo data model + hand-seeded example basket  | —             | FR-006, FR-007, FR-008, FR-009    | done |
| F-02 | oauth-authentication             | (foundation) OAuth login + open registration wired                | —             | FR-002, Access Control            | done |
| F-03 | leaflet-vision-ingestion         | (foundation) per-chain leaflet→structured-data ingestion + CLI trigger | F-01      | FR-006, FR-009                    | in-progress |
| S-01 | guest-fixed-basket-comparison    | (guest) see a fixed example-basket comparison + verdict on home   | F-01          | FR-001, FR-007, US-01             | done |
| S-02 | basket-builder-comparison-report | build a basket and generate the full Lidl vs Biedronka report     | S-01, F-02    | FR-002, FR-003, FR-004, FR-008, US-01 | done |
| S-03 | save-and-revisit-basket          | save a basket and return to re-compare it after a refresh         | S-02          | FR-005                            | proposed |
| S-04 | nightly-refreshed-real-data      | get comparisons on real, nightly-refreshed leaflet data           | F-03, S-01    | FR-006, FR-009                    | proposed |

## Streams

Navigation aid — groups items that share a Prerequisites chain. Canonical ordering still lives in the dependency graph below; this table is the proposed reading order across parallel tracks.

| Stream | Theme                | Chain                                     | Note                                                                                                     |
| ------ | -------------------- | ----------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| A      | Verdict & demo       | `F-01` → `S-01` → `S-02` → `S-03`         | Market-feedback critical path: prove the verdict (north star) first, then build the account flow on top. `F-02` joins at `S-02`. |
| B      | Account (OAuth)      | `F-02`                                    | Standalone foundation; joins Stream A at `S-02`. Runs parallel to the entire guest path.                 |
| C      | Real leaflet data    | `F-03` → `S-04`                           | Unblocked 2026-08-29: per-chain split — Lidl is exact PDF text, only Biedronka needs a vision model. Isolated behind the seed, so it never blocked Stream A. `F-03` branches off `F-01`. |

## Baseline

What's already in place in the codebase as of `2026-07-21` (auto-researched + user-confirmed).
Foundations below assume these are present and do NOT re-scaffold them.

- **Frontend:** partial — Tailwind 4 + Vite 8 wired (`vite.config.js`, `@tailwindcss/vite`); only `welcome.blade.php` exists. No app layout/components yet.
- **Backend / API:** present — Laravel 13.8 scaffold; base `Controller` only; `routes/web.php` has `/` + `/_version`. Framework ready, no feature code.
- **Data:** partial — MySQL 8.0 configured (per `tech-stack.md`); only 3 default migrations (users, cache, jobs) + `User` model. No domain schema (products/prices/promos/baskets).
- **Auth:** absent — no Socialite / Breeze / Jetstream / Fortify in `composer.json`; default `User` model only. OAuth planned, not installed.
- **Deploy / infra:** present — `.github/workflows/deploy.yml` + `deploy/release.sh` + `setup-server.sh` + `SERVER-SETUP.md`; `/_version` verification route wired (`deploy-plan.md`).
- **Observability:** absent — no Sentry/Telescope/Flare; Laravel default file logging only. Scheduler failure-alerting flagged in `infrastructure.md` but not wired.

## Foundations

### F-01: Price/promo data model + seed fixture

- **Outcome:** (foundation) a minimal schema for products, per-network prices, the four promo mechanic types (with their parameters), and leaflet validity windows is in place, plus a hand-seeded dataset for one example basket.
- **Change ID:** price-promo-data-model-seed
- **PRD refs:** FR-006, FR-007, FR-008, FR-009 (data shape they all consume); NFR (data-freshness / validity windows); Business Logic.
- **Unlocks:** S-01 (guest comparison renders a verdict computed over this seed), F-03 (ingestion writes into this schema), S-02.
- **Prerequisites:** — (baseline data layer is partial: only default Laravel migrations exist)
- **Parallel with:** F-02
- **Blockers:** —
- **Unknowns:**
  - Rough data volume (price/promo entries per week for two chains) — Owner: user. Block: no. (PRD Open Question 2; sizing only, does not block a minimal schema.)
- **Risk:** the promo-type modeling is load-bearing — if the four mechanics can't be represented cleanly as data, both the rule engine (S-01) and ingestion (F-03) churn. Kept deliberately minimal (one seed, no ingestion) so S-01 exercises it immediately rather than pre-building the whole data layer.
- **Status:** done

### F-02: OAuth authentication (Socialite)

- **Outcome:** (foundation) OAuth login via a configured provider works, registration is open, and authenticated sessions are issued — no email+password path.
- **Change ID:** oauth-authentication
- **PRD refs:** FR-002; Access Control (guest vs authenticated user).
- **Unlocks:** S-02 (logged-in basket + report), S-03 (per-account saved baskets).
- **Prerequisites:** — (baseline auth is absent)
- **Parallel with:** F-01, F-03, S-01
- **Blockers:** —
- **Unknowns:**
  - Which OAuth provider(s) to ship first (Google named as the example in FR-002) — Owner: user. Block: no. (Google is a safe working default.)
- **Risk:** Socialite is not yet installed (baseline), but this is a well-trodden Laravel path with low technical risk. Sequenced as a standalone foundation because login on its own delivers no product value — it only gates S-02/S-03 — and it parallelizes fully with the guest path.
- **Status:** done

### F-03: Leaflet vision-ingestion pipeline

- **Outcome:** (foundation) a queued job turns each chain's leaflet into structured price/promo rows (with leaflet expiry dates) in F-01's schema, through two parsers — Lidl from its PDF text layer, Biedronka from page images via a vision model — behind one driver interface; a CLI command can trigger a refresh manually.
- **Change ID:** leaflet-vision-ingestion
- **PRD refs:** FR-006 (structure a graphic leaflet into the price/promo DB); FR-009 (feeds the nightly refresh); shape-notes `## Forward: technical-roadmap` (CLI trigger, multi-source-ready architecture).
- **Unlocks:** S-04 (comparisons on real, refreshed data); replaces the hand-seed as the source of every price in the product.
- **Prerequisites:** F-01 (writes into its schema)
- **Parallel with:** F-02, S-01, S-02, S-03 (all run on the seed and do not depend on ingestion)
- **Blockers:** — (resolved: `context/research/vision.md` verified both chains are reachable over plain HTTP, so there is no vendor to contract and no browser worker to host)
- **Unknowns:**
  - How accurately does a vision model read Biedronka's page images — enough to price a verdict, or only enough with a `needs_review` gate? — Owner: user. Block: no. (Measured in F-03's own phase 1 against a hand-labelled gold set; the research pre-commits the decision rule and a Lidl-only fallback, so an answer either way still ships.)
- **Risk:** scope grew to two parsers, but risk dropped sharply: Lidl's PDF text layer is exact by construction, so half the corpus carries no extraction risk at all. The remaining risk is confident-but-wrong numbers from Biedronka's images — the failure the PRD guardrail exists to catch — which the plan must answer with deterministic post-extraction validation and provenance columns on `price_entries`, not with model confidence.
- **Status:** in-progress

## Slices

### S-01: Guest fixed example-basket comparison (north star)

- **Outcome:** a guest sees, on the homepage, a fixed example-basket comparison of Lidl vs Biedronka with a "where is it cheaper" verdict and all four promo mechanics (simple promo price, 1+1 free, second-for-1-PLN/grosz, loyalty-card price) correctly priced.
- **Change ID:** guest-fixed-basket-comparison
- **PRD refs:** FR-001, FR-007, US-01 (partial — the verdict + promo pricing, without the logged-in basket builder); Business Logic; NFR mobile-first.
- **Prerequisites:** F-01
- **Parallel with:** F-02, F-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** this slice introduces the promo-mechanics rule engine (the wedge) and carries the four mandatory PHPUnit promo tests required by CLAUDE.md — correctness here is the whole product's credibility. Placed first because it proves the core hypothesis with no auth and no ingestion prerequisite.
- **Status:** done

### S-02: Basket builder + full comparison report

- **Outcome:** a logged-in user builds a basket (products + optional quantities, default 1) and generates the full Lidl vs Biedronka report: verdict, priced promo mechanics (including the forced overbuy shown as a cost), explicit matched-product pairs (brand, weight difference), and the visible from–to leaflet-validity window per price.
- **Change ID:** basket-builder-comparison-report
- **PRD refs:** FR-002 (the flow begins behind OAuth login), FR-003, FR-004, FR-008, US-01 (full); NFR mobile-first, NFR <2s responsiveness, NFR data-freshness transparency.
- **Prerequisites:** S-01 (reuses the rule engine), F-02 (auth)
- **Parallel with:** F-03
- **Blockers:** —
- **Unknowns:**
  - Expected traffic order-of-magnitude (qps) for the <2s budget under concurrency — Owner: user. Block: no. (PRD Open Question 1; a single local MySQL comparison is well within budget with eager loading — this only affects capacity planning.)
- **Risk:** the largest user-facing slice. Must hit the <2s budget with real cross-network product matching (FR-008) and eager-loaded relations (no N+1, per CLAUDE.md). Matching correctness directly affects verdict trust, so pairings are always shown explicitly for the user to judge.
- **Status:** done

### S-03: Save and revisit a basket

- **Outcome:** a user saves a basket to their account and returns later to re-compare it after the data has refreshed; saved baskets are visible only to their owner.
- **Change ID:** save-and-revisit-basket
- **PRD refs:** FR-005; NFR basket privacy (owner-only visibility).
- **Prerequisites:** S-02
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** mostly standard persistence + ownership scoping; low technical risk. The load-bearing requirement is the privacy NFR — saved baskets must never leak across accounts — so authorization scoping is the thing to get right.
- **Status:** proposed

### S-04: Comparisons on real, nightly-refreshed leaflet data

- **Outcome:** comparisons run on real leaflet data refreshed automatically once nightly; every price carries a visible validity window, and when data is incomplete or expired the verdict returns "no data" instead of a wrong answer.
- **Change ID:** nightly-refreshed-real-data
- **PRD refs:** FR-006, FR-009; Success Criteria guardrail ("the verdict never lies"); NFR data-freshness transparency.
- **Prerequisites:** F-03, S-01
- **Parallel with:** S-02, S-03 (they run on the seed; this slice swaps the data source underneath them)
- **Blockers:** — (F-03 is an ordinary prerequisite, not a blocker)
- **Unknowns:**
  - How aggressively must the "no data" fallback fire on Biedronka? — Owner: user/team. Block: no. (Falls out of F-03 phase 1's measurement.)
- **Risk:** this is where the trust guardrail becomes real — expiry and completeness checks must gate the verdict so stale/partial data yields "no data", not a confident lie. It also has a partial-ship path the roadmap previously hid: because Lidl's data is exact, this slice can ship on real Lidl prices with Biedronka still on the seed, rather than being all-or-nothing on vision accuracy.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID                        | Suggested issue title                                      | Ready for `/10x-plan` | Notes |
| ---------- | -------------------------------- | ---------------------------------------------------------- | --------------------- | ----- |
| F-01       | price-promo-data-model-seed      | Price/promo data model + example-basket seed              | yes                   | No prerequisites; unlocks the north star. Recommended first. |
| F-02       | oauth-authentication             | OAuth login (Socialite) + open registration              | yes                   | Independent; parallel with the guest path. |
| F-03       | leaflet-vision-ingestion         | Per-chain leaflet ingestion (Lidl PDF + Biedronka vision) | yes                  | Unblocked. Plan must carry `price_entries` provenance columns + the validation gate (`context/research/vision.md` §9). |
| S-01       | guest-fixed-basket-comparison    | Guest homepage fixed-basket comparison + promo rule engine | no                  | Needs F-01 done first; then plan this (north star). |
| S-02       | basket-builder-comparison-report | Basket builder + full Lidl vs Biedronka report            | no                    | Needs S-01 + F-02. |
| S-03       | save-and-revisit-basket          | Save & revisit basket (owner-only)                        | no                    | Needs S-02. |
| S-04       | nightly-refreshed-real-data      | Comparisons on real nightly-refreshed data + "no data"    | no                    | Needs F-03 first. Has a Lidl-only partial-ship path. |

## Open Roadmap Questions

1. **Traffic order-of-magnitude (qps)?** — Owner: user. Block: `S-02` (informs the <2s budget under concurrency; does not gate planning — a single local-MySQL comparison is comfortably within budget). From PRD Open Question 1.
2. ~~**Data volume (price/promo entries per week for two chains)?**~~ — **Resolved 2026-08-29** (`context/research/vision.md` §7): Lidl ~95 pages/week from a PDF text layer, Biedronka ~53 pages/week as images for the main leaflet; Biedronka runs 13 concurrent leaflets, so MVP ingests the main food leaflet only.
3. ~~**Which vision-API vendor for leaflet ingestion, and is its parsing accuracy/cost acceptable?**~~ — **Resolved 2026-08-29** (`context/research/vision.md` §1, §8): the question was per-chain, not global. Lidl needs no vendor at all (exact PDF text); Biedronka uses Gemini 3.5 Flash-Lite, with Claude Haiku 4.5 and Mistral OCR 4.1 measured alongside it in F-03 phase 1. Vision spend lands under $2/month, so cost is not a discriminator — accuracy and auditability are.
4. ~~**Which OAuth provider(s) to ship first?**~~ — **Resolved**: Google shipped with F-02.

## Parked

- **More chains than Lidl and Biedronka** — Why parked: PRD §Non-Goals (v2; name and architecture stay chain-agnostic).
- **Advanced product matching** (quality/substitute/weight-normalization) — Why parked: PRD §Non-Goals (MVP does simple equivalents with explicit brand/weight difference only).
- **Per-store local prices / geolocation** — Why parked: PRD §Non-Goals (one nationwide leaflet price).
- **Price history & trends** — Why parked: PRD §Non-Goals (only current leaflet data; no charts/alerts).
- **Leaflet page preview** — Why parked: PRD §Non-Goals (v2; report is built on structured data, not images).
- **Admin panel / user management** — Why parked: PRD §Non-Goals (operations via CLI/jobs in MVP; user management in v2).
- **Email+password login** — Why parked: PRD §Non-Goals (OAuth-only in MVP; no email sending).
- **On-demand refresh in the product UI** — Why parked: PRD §Non-Goals (automatic nightly cron + CLI only).
- **Other source formats (API, PDF)** — Why parked: PRD §Non-Goals + shape-notes `## Forward` (ingestion architecture is multi-source-ready, but only the graphic-format provider ships in MVP).

## Milestone History

(Append-only. Carried forward verbatim into each successor milestone's roadmap.)

- **M-01: MVP — promo-aware basket verdict** (`mvp-promo-aware-basket-verdict`) — opened, adopted from the pre-milestone roadmap on `2026-08-29`. No milestone has closed yet.

## Done

(Empty on first generation. `/10x-archive` appends here — and flips the item's Status to `done` — when a change whose Change ID matches a roadmap item is archived. Do NOT pre-populate.)

- **F-01: (foundation) a minimal schema for products, per-network prices, the four promo mechanic types (with their parameters), and leaflet validity windows is in place, plus a hand-seeded dataset for one example basket.** — Archived 2026-08-29 → `context/archive/2026-07-25-price-promo-data-model-seed/`. Lesson: —.
- **F-02: (foundation) OAuth login via a configured provider works, registration is open, and authenticated sessions are issued — no email+password path.** — Archived 2026-08-29 → `context/archive/2026-08-29-oauth-authentication/`. Lesson: —.
- **S-01: a guest sees, on the homepage, a fixed example-basket comparison of Lidl vs Biedronka with a "where is it cheaper" verdict and all four promo mechanics (simple promo price, 1+1 free, second-for-1-PLN/grosz, loyalty-card price) correctly priced.** — Archived 2026-08-29 → `context/archive/2026-07-25-guest-fixed-basket-comparison/`. Lesson: —.
- **S-02: a logged-in user builds a basket (products + optional quantities, default 1) and generates the full Lidl vs Biedronka report: verdict, priced promo mechanics (including the forced overbuy shown as a cost), explicit matched-product pairs (brand, weight difference), and the visible from–to leaflet-validity window per price.** — Archived 2026-08-29 → `context/archive/2026-08-29-basket-builder-comparison-report/`. Lesson: —.
