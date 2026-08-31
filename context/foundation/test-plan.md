# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-08-31

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic visual diff that already catches
   the regression.
2. **User concerns are first-class evidence.** Risks anchored in "the
   developer is worried about X, and the failure would surface somewhere in
   `<area>`" carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `app/`, `resources/views/`,
`routes/`, `database/`, `tests/` (30 days, 40 commits; excluding `context/`,
`.claude/`, `vendor/`, `node_modules/`, `public/build/`, lockfiles).

> **Metric caveat (added 2026-08-30 by Phase 1 research).** The per-directory
> figures in §2's Source column count *file-level changes*, not commits —
> `git log --since=30.days --oneline -- app/Pricing` returns 4, not 17, and
> `database/factories` returns 5, not 7. Read them as relative churn signal
> only; do not re-quote them as commit counts. The corrected figures are
> carried in the two rows Phase 1 verified.

## 2. Risk Map

The top failure scenarios this project must protect against, ordered by
risk = impact × likelihood. Risks are failure scenarios in user / business
terms, not test names. The Source column cites the *evidence that surfaced
this risk* — never a specific file as "where the failure lives" (that is
research's job, see §1 principle #3).

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | A promo mechanic is mispriced on a leaflet shape the hand-seed never contained, and the verdict names the wrong chain | High | High | **Confirmed realised by Phase 1 research (2026-08-30)** — a recorded real Lidl offer shape is mispriced today and flips the verdict; PRD FR-007 + its 2026-08-30 extension (real data already broke the four-mechanic model once); PRD Business Logic; interview Q1, Q3; hot-spot dir `app/Pricing` (4 commits/30d — see metric caveat in §1) |
| 2 | The report omits or misstates the audit trail the verdict rests on — validity window per price, forced-overbuy cost, explicit matched pair — so a correct verdict is unverifiable and a wrong one is undetectable | High | High | PRD FR-008; PRD NFR "Transparentność świeżości danych"; PRD Business Logic; interview Q2, Q4; hot-spot dirs `resources/views/layouts` (4 commits/30d), `resources/views` (4) |
| 3 | Expired, incomplete or unmatched data still yields a confident verdict instead of "brak danych" | High | Medium | PRD Success Criteria §Guardrails; PRD FR-008, FR-009; roadmap S-04 status `proposed` (seed is about to be swapped for real, sometimes-partial data); hot-spot dir `app/Pricing` (17 commits/30d) |
| 4 | Extraction persists a plausible-but-wrong row — a pack price read as a unit price, the wrong mechanic type, a misread threshold N — which the validation gate accepts as fact | High | Medium | roadmap F-03 §Risk ("confident-but-wrong numbers … the failure the PRD guardrail exists to catch"); PRD FR-006; interview Q3; hot-spot dirs `app/Ingestion` (7 commits/30d), `app/Ingestion/Drivers` (6) |
| 5 | A saved basket is reachable by someone who does not own it (abuse lens — authorization/IDOR) | High | Medium | PRD NFR "Prywatność koszyków"; PRD §Access Control; roadmap S-03 §Risk ("authorization scoping is the thing to get right"); hot-spot dirs `app/Http/Controllers` (9 commits/30d), `routes` (6) |
| 6 | Test fixtures encode a shape production cannot hold, so a green suite proves nothing about production behaviour | Medium | High | **Confirmed realised by Phase 1 research (2026-08-30)** — the rule is violated in the live suite again, via a factory path the existing fixture test does not cover; `context/foundation/lessons.md` — "Never let related factories each create their own parent" (already happened once; caught by review, not by a test); interview Q2; hot-spot dir `database/factories` (5 commits/30d — see metric caveat in §1) |
| 7 | The basket accepts a quantity the domain cannot price honestly (zero, negative, absurd) and computes a verdict from it anyway (abuse lens — untrusted input / server-side validation parity) | Medium | Medium | PRD FR-003 (quantity optional in the UI, default 1); PRD Business Logic (conditional promos priced off the actual quantity); hot-spot dir `app/Http/Controllers` (9 commits/30d) |

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | For a basket and a known real-leaflet offer shape, the computed total equals what a shopper actually pays at the till — the shopper charged for exactly the quantity asked for, with the forced overbuy *disclosed* rather than added to the total — and, on a deliberate near-tie, the verdict names the chain the till agrees with | That the five mechanics are protected because five tests exist (they assert a total against a single chain, so none of them can produce a wrong verdict); that a mechanic verified at its seeded threshold stays correct at the thresholds real leaflets print | Where quantity enters pricing; how each mechanic's parameters are persisted; which offer shapes real leaflets produced after F-03 | unit (mechanic × threshold × quantity matrix — the calculator is pure and needs no DB) + integration (two chains, for the verdict) | One happy-path total per mechanic and nothing else; a single threshold value standing in for every threshold; a verdict assertion over a one-chain fixture; adding forced-overbuy units into the expected total (the PRD says disclose, not charge) |
| #2 | A rendered report states, per price, its from–to validity window; names the forced extra units as a cost; and shows each matched pair's brand/weight difference | That data present on the model means the user sees it; that asserting Polish copy is the same as asserting behaviour | Which template renders the report and what the controller hands it; where validity windows, overbuy cost and pair metadata originate | integration (HTTP feature test asserting rendered content) | Full-page snapshots (excluded — see §7); asserting markup structure instead of the required fact |
| #3 | With expired, missing or unpaired data the response withholds a winner and says "brak danych" — and abstention is decided for the basket, not swallowed line by line | That a non-empty result means the data was complete; that "now" in a test coincides with a leaflet's live window | Expiry semantics on price rows; what "unmatched" means at comparison time; how an abstaining line propagates to the basket verdict | integration + time travel (`travelTo`) | Happy-path only; asserting that the abstention branch exists without asserting that a verdict is actually withheld |
| #4 | A parser output that is wrong but well-formed is rejected or flagged for review rather than persisted as a priceable fact | That the gate passing means the number is right; that model confidence is evidence of correctness | The gate's accept/reject/flag contract; provenance columns on price rows; which recorded real leaflet fragments can serve as fixtures | hermetic (stubbed acquirer / vision client) + recorded fixtures | Live calls to chain sites or a vision API inside the suite; mocking the gate itself instead of feeding it bad input |
| #5 | Every route that reads, re-compares, renames or deletes a saved basket refuses a non-owner outright — and never discloses another account's basket contents | That one privacy test on one route covers the whole resource; that being authenticated implies owning the record | The full inventory of routes touching a saved basket; what the existing privacy coverage actually asserts today | integration (HTTP) | Testing only the list/index route; asserting a redirect happened without asserting nothing was disclosed |
| #6 | A factory row is a shape production can hold — chain, leaflet and price agree, and the values fit the production column types — and a test fails if it is not | That a passing suite implies valid fixtures (it does not — the suite is green today on rows that violate the rule); that covering the default row covers the factory (the `->for()` path escapes the derivation); that SQLite enforces what MySQL 8.0 enforces | How parents are derived across the price/promo factories; which invariants no composite key can express; which row shapes SQLite accepts and MySQL 8.0 rejects | integration (real DB constraints) — plus one narrow lane against MySQL 8.0, which is the only layer that can see range/type violations | Re-deriving each factory's own logic inside its assertion; covering only the default `create()` path and not the states or the `->for()` override |
| #7 | Out-of-domain quantities are rejected at the request boundary with a Polish validation message, and no verdict is computed from them | That the UI default of 1 protects the server | Where quantity is validated versus where it is consumed; whether the session basket and the persisted basket share that boundary | integration (HTTP) | Testing the form widget rather than the request boundary |

## 3. Phased Rollout

Each row is a discrete rollout phase that will open its own change folder
via `/10x-new`. Status moves left-to-right through the values below; the
orchestrator updates Status as artifacts appear on disk.

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | Verdict correctness on real shapes | Prove the till-total for every promo mechanic on the offer shapes real leaflets actually produce, on fixtures that cannot encode an impossible row | #1, #6 | unit + integration | complete | `context/changes/testing-verdict-correctness/` |
| 2 | Report as the user reads it | Prove the report states the facts that make the verdict auditable: validity window per price, forced-overbuy cost, explicit matched pair | #2 | integration (HTTP content) | not started | — |
| 3 | Honest abstention | Prove "brak danych" wins over a guess when data is expired, incomplete or unpaired | #3 | integration + time travel | not started | — |
| 4 | Account boundary and input contract | Prove owner-only access on every saved-basket route, and that the server rejects quantities the domain cannot price | #5, #7 | integration (HTTP) | not started | — |
| 5 | Ingestion trust boundary | Prove a wrong-but-well-formed extraction never becomes a priceable fact | #4 | hermetic + recorded fixtures | not started | — |

Ordering note: phases 1 and 2 are ordered by *gap* × impact, not impact
alone. Pricing already carries ~30 test methods and ingestion ~24, while the
rendered report carries none — so the cheapest large signal available is the
report, even though ingestion feeds the same verdict. Phase 3 must land
before roadmap S-04 swaps the hand-seed for real, sometimes-partial data.
Phase 5 has the highest setup cost and the best relative coverage today; it
is the natural place to stop or defer if the rollout runs long.

## 4. Stack

The classic test base for this project. AI-native tools (if any) carry a
`checked:` date so future readers can see which lines need re-verification.

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration | PHPUnit | ^12.5.12 | `composer test` clears config then runs `artisan test`; suites `Unit` and `Feature` |
| test database | SQLite `:memory:` | n/a | Set in `phpunit.xml`; production is MySQL 8.0 — see the divergence note below |
| fixtures | Eloquent factories + `fakerphp/faker` | ^1.23 | Already burned once (`lessons.md`); Phase 1 makes fixture integrity assertable |
| HTTP / view assertions | Laravel feature tests | ^13.8 | Content assertions on the rendered report; no snapshot layer — see §7 |
| test doubles | Mockery | ^1.6 | For the hermetic ingestion layer in Phase 5 |
| external HTTP faking | `Http::fake` (framework) | ^13.8 | No leaflet site or vision API is ever called from the suite |
| time control | `travelTo` / time freezing (framework) | ^13.8 | Required by Phase 3 for leaflet validity windows; checked: 2026-08-30 |
| queue / console assertions | `Queue::fake`, `$this->artisan(...)` | ^13.8 | Available for the ingestion command; checked: 2026-08-30 |
| style | Pint (Laravel preset) | ^1.27 | `composer lint` / `composer fix`; already gated in CI |
| e2e / browser | Playwright (`@playwright/test`) + Playwright CLI | ^1 | *Added 2026-08-31.* Runs on the **host** against `https://koszykomat.ddev.site`, not inside the container — see the browser-layer note below. Deliberately narrow: it covers NFRs no feature test can reach, not the §2 risks |
| accessibility | none yet | — | Not covered by any phase in §3; out of scope for this rollout |
| AI-native | none | — | No phase justified one under cost × signal |

**MySQL/SQLite divergence.** The whole suite runs on SQLite `:memory:` while
production runs MySQL 8.0 (`CLAUDE.md` hard constraints).

*Revised 2026-08-30 by Phase 1 research, which measured this on both engines
rather than reasoning about it.* The **arithmetic** half of this worry is
disproved: no money arithmetic is pushed to the database, `App\Pricing\Money`
computes with BCMath on decimal strings at an explicit scale, and the
`decimal:2` cast normalises SQLite's float back to the same string MySQL
stores — every probed value agreed after casting. A basket total asserted on
SQLite does hold on MySQL, so this is no longer a "must challenge" line on
Risk #1.

The **constraint** half is real and moved to Risk #6. SQLite's schema grammar
drops precision and scale, so it silently accepts rows MySQL 8.0 in strict
mode rejects outright (measured: a threshold above the column's integer range,
and a price above the decimal column's range — both stored by SQLite, both
`SQLSTATE 22003` on MySQL). Since the ingestion path deliberately writes raw
parser output into those columns, a fixture can encode a row production cannot
hold and the suite stays green. That is Risk #6 exactly, and a narrow MySQL
lane is the only layer that sees it — see §7.

**Browser layer (added 2026-08-31).** §4 previously read "e2e / browser: none —
deliberate". That line is now narrowed rather than reversed, and the reasoning
is worth keeping, because it decides what an E2E test here may and may not claim.

What E2E does *not* buy: Risk #2 (the report's audit trail) and Risk #5
(saved-basket ownership) are already owned by §3 Phases 2 and 4 as Laravel HTTP
feature tests. Those are faster, hermetic and assert the same facts. Driving a
browser at them would add cost and flakiness for no new signal, so the browser
layer must not duplicate them.

What only a browser can see: the PRD's two NFRs that no feature test touches —
"mobile-first: the whole flow (login → basket → report) is fully usable on a
phone", and the survival of a real session across the full path, where auth,
CSRF, redirects and the session store all have to agree. A feature test asserts
each hop; only a browser asserts the journey.

**The `/_test/login` door.** Auth is OAuth-only (FR-002) and Playwright cannot
drive Google, so `routes/web.php` registers a session bypass **in the local
environment only**, guarded twice and pinned by
`tests/Feature/Auth/TestLoginRouteTest.php`. That test is not optional
scaffolding: without it the bypass is one misconfigured `APP_ENV` away from
being a credential-free entrance to every account.

**Stack grounding tools (current session):**
- Docs: Context7 (`/websites/laravel_13_x`) — verified that `Queue::fake`, console-command assertions and time-travel helpers are current in Laravel 13.x; checked: 2026-08-30
- Search: none — no Exa.ai or web-search MCP available in current session; stack facts came from `composer.json`, `phpunit.xml` and Context7
- Runtime/browser: claude-in-chrome MCP available — not used; no phase in §3 needs a browser layer
- Provider/platform: none — no GitHub MCP in current session; CI facts read directly from `.github/workflows/deploy.yml`

## 5. Quality Gates

The full set of gates that must pass before a change reaches production.
"Required for §3 Phase `<N>`" means the gate is enforced once that rollout
phase lands; before that, the gate is planned.

| Gate | Where | Required? | Catches |
|---|---|---|---|
| Pint style check (`composer lint`) | local + CI (pre-deploy) | required — already wired | style drift |
| PHPUnit suite (`composer test`) | local only | required before push — deliberately not in CI (`98f6a3a`); runs a **filtered** suite, see the lane note below | logic regressions |
| Known-defect suite (`composer test:all`) | local, ad hoc | informational — expected red while a documented defect stands | that a documented defect is still present |
| Fixture-shape suite (`composer test:mysql`) | local, ad hoc | required after §3 Phase 1 — the integration gate §5 anticipated | fixtures MySQL 8.0 would reject |
| Promo-mechanic total assertions | in the PHPUnit suite | required — mandated by `CLAUDE.md`; hardened by §3 Phase 1 | a mispriced mechanic flipping the verdict |
| Fixture-integrity assertions | in the PHPUnit suite | required after §3 Phase 1 | factories producing rows production cannot hold |
| Report-content assertions | in the PHPUnit suite | required after §3 Phase 2 | the verdict losing its audit trail |
| Abstention assertions | in the PHPUnit suite | required after §3 Phase 3 | a confident verdict over stale or partial data |
| Ownership assertions on saved baskets | in the PHPUnit suite | required after §3 Phase 4 | cross-account basket disclosure |
| Hermetic ingestion-gate assertions | in the PHPUnit suite | required after §3 Phase 5 | wrong-but-well-formed rows becoming priceable facts |
| Mutation check (Infection, narrow scope) | local, ad hoc | optional — after §3 Phase 1 or 3 only | assertions that execute code without proving anything |

CI (`.github/workflows/deploy.yml`) gates deployment on Pint only; the test
step was removed in `98f6a3a` so the suite runs locally. Consequence for this
rollout: every "in the PHPUnit suite" row above is enforced by developer
discipline, not by the pipeline — a push that skipped `composer test` reaches
production with the phase's protection unverified. No rollout phase in §3
addresses this, because it is a workflow decision rather than a test gap. If
the local-only rule ever slips, the fix is to restore the CI test step, not
to add a phase.

**Test lanes (added 2026-08-31 by §3 Phase 1).** A green `composer test` does
NOT mean every test passed — it runs a filtered suite:

| Command | Runs | Expected |
|---|---|---|
| `composer test` | everything except `known-defect` and `mysql` | green — this is the pre-push gate |
| `composer test:all` | adds `known-defect`; still excludes `mysql` | **red** while a documented defect stands |
| `composer test:mysql` | only `mysql`, against MySQL 8.0 | green |
| `composer test:mysql:setup` | one-off: create + grant + migrate `koszykomat_test` | idempotent |

`composer test:all` is red today by design: Phase 1 documented that
`PromoCalculator::conditional()` is correct only at `required_quantity = 2`,
while real Lidl leaflets print N=3. Seven cases assert the till truth and fail.
They are expected to turn green with no edit once that defect is fixed — until
then, red there is a standing record, not a broken suite. **If it ever goes
green without a fix, the filtering broke, not the engine.**

Two mechanical traps, both found the hard way:

- `--exclude-group` must be **repeated per group**. `--exclude-group=a,b`
  parses without error and silently filters *nothing* — it let every
  known-defect case into the gate.
- The `mysql` group must never run in a SQLite lane: its acceptance cases would
  pass vacuously and its rejection cases would fail. `composer test:all`
  excludes it, and the class hard-fails on the wrong driver rather than
  skipping.

## 6. Cookbook Patterns

How to add new tests in this project. Each sub-section is filled in once
the relevant rollout phase ships; before that, the sub-section reads
"TBD — see §3 Phase `<N>`."

### 6.1 Adding a promo-mechanic pricing test

Reference: `tests/Unit/Pricing/PromoCalculatorTest.php` (totals),
`tests/Feature/Pricing/BasketComparatorVerdictTest.php` (verdicts).

- **Assert the till total on the unit layer.** `PromoCalculator` reads
  attributes off a `PriceEntry` and returns a `LineCost`, so an unsaved model
  is the whole fixture — no database, no factory, milliseconds per case. Reach
  for an integration test only when the *verdict* is what you are asserting.
- **Derive the expected value from the leaflet or the PRD, never from the
  engine.** Writing `regular + second × (N − 1)` reproduces the formula under
  test and would pass against a bug. Quote the leaflet phrase in the docblock
  and put the derivation in the data-set key, so a failure explains itself
  without opening the source.
- **Sweep the thresholds real leaflets print, not just the seeded one.** The
  N=3 defect survived five green mechanic tests because every seeded row uses
  N=2, the one value where the buggy formula is right. Observed thresholds so
  far: **2, 3, 6**. Use `#[DataProvider]` and boundary quantities
  {1, N−1, N, N+1, 2N}.
- **Cover the unpriceable shapes too.** A `conditional_unit_price` row with a
  null `promo_price` is not an edge case — it is the state every real Lidl
  conditional row is in, and it must yield `null`, never a guess.
- **Overbuy is disclosed, never charged.** The shopper pays for exactly the
  quantity asked for (`PromoCalculator` docblock; PRD §Business Logic). Never
  add forced-overbuy units into an expected total. Assert
  `promoRequiredMoreItems` separately — and remember a *remainder* is not a
  shortfall: at N=6, quantity 7, a group fired and the flag stays down.
- **A verdict needs two chains.** With one chain `decide()` returns a winner
  with a zero margin — a verdict over nothing, and a shape production never
  holds. Engineer a deliberate near-tie so the mechanic decides the outcome; a
  wide margin survives almost any mispricing and proves nothing.
- **A test that documents a known defect goes in the `known-defect` group.**
  It then runs under `composer test:all`, stays out of the pre-push gate, and
  turns green by itself when the defect is fixed. Groups apply per *method*,
  so a mechanic needs two methods — one for thresholds that pass, one tagged —
  rather than one provider mixing both.

### 6.2 Adding or changing a factory

Reference: `database/factories/PriceEntryFactory.php`,
`tests/Feature/Database/PriceEntryFactoryTest.php`,
`tests/Feature/Database/FixtureShapeMySqlTest.php`.

- **Never pin only the listing.** `PriceEntry::factory()->for($listing,
  'networkProduct')` replaces the derivation that keeps the leaflet in the same
  chain, leaving a fresh Leaflet in a chain of its own. Use `forListing($listing)`,
  or pin **both** parents from one network (`->for($leaflet)->for($listing,
  'networkProduct')`, as `BasketComparatorTrustTest` does). The factory's
  `configure()` guard throws on an incoherent row, so a `LogicException`
  mentioning "cross-chain" means the fixture is wrong, not the guard.
- **Add every new state to the coherence provider.** `conditional_unit_price`
  was added to the factory and left out of that list, so the fifth mechanic's
  state went uncovered. `PriceEntryFactoryTest::promoStates()` is the list.
- **Keep a guard test that proves the guard bites.** Without
  `test_pinning_only_the_listing_is_refused()`, the coherence tests would pass
  on a factory whose guard had been deleted — green proving only that nobody
  tried.
- **Compare the two chains to each other; do not re-derive the factory's
  logic.** The assertion is `leaflet.network_id === networkProduct.network_id`,
  not a recomputation of how the parent was resolved.
- **Range and type validity belong in the `mysql` group.** SQLite emits a bare
  `numeric` column and ignores integer width, so it stores rows MySQL 8.0
  rejects. Anything asserting what a column *permits* goes in
  `FixtureShapeMySqlTest` and runs via `composer test:mysql`. Assert both
  directions — rows accepted **and** out-of-range rows rejected; an acceptance-only
  class passes vacuously.
- **Defaults are load-bearing.** Threshold parameters were added with their
  original values as defaults precisely so no existing test moved. Keep it that
  way when parameterising a state.

### 6.3 Adding a test for a rendered report or view

- TBD — see §3 Phase 2, for the content-assertion pattern (Risk #2:
  validity window, forced-overbuy cost and matched-pair disclosure). Note
  the boundary with §7 — assert facts, never snapshots.

### 6.4 Adding a test for data freshness or abstention

- TBD — see §3 Phase 3, for the time-travel + partial-data pattern
  (Risk #3: "brak danych" must beat a guess).

### 6.5 Adding a test for a new route or controller

- TBD — see §3 Phase 4, for the ownership and input-boundary pattern
  (Risks #5, #7).

### 6.6 Adding a test for an ingestion parser or the validation gate

- TBD — see §3 Phase 5, for the hermetic stub + recorded fixture pattern
  (Risk #4: never call a chain site or a vision API from the suite).

### 6.7 Per-rollout-phase notes

**Phase 1 — Verdict correctness on real shapes (2026-08-31).** Three things
surprised us, all of the same species: a green result that proved nothing.

- The five mandatory mechanic tests could never have caught the N=3 defect,
  because they seed a single chain — they assert a total, and Risk #1 is about a
  verdict. Coverage counted; construction did not.
- `--exclude-group=a,b` was verified during planning by running it when no test
  carried either group. "Passed" proved only that the flag parses. The first
  real run let all six failures into the gate.
- Dropping the MySQL test schema looked like a failure mode and is not: Laravel
  creates a missing database itself, and a database-level grant survives
  `DROP DATABASE`. The one-off setup step earns its keep for the **grant**.

Also worth carrying forward: the factory guard that refuses a cross-chain row
was cheaper and stronger than any test asserting the same thing, because it
fires at the point of creation in every test, present and future.

## 7. What We Deliberately Don't Test

Exclusions agreed during the rollout (Phase 2 interview, Q5). Future
contributors should respect these unless the underlying assumption changes.

- **Snapshot and pixel-diff tests of views** — they break on every Tailwind
  or copy tweak and catch nothing about correctness. Asserting that the
  report *states a required fact* (§3 Phase 2) is a different thing and is
  in scope. Re-evaluate only if a rendering regression ever reaches
  production undetected. (Source: Phase 2 interview Q5.)
- **A second MySQL test lane for the whole suite** — the suite stays on
  SQLite `:memory:`. *Narrowed 2026-08-30 by Phase 1 research:* the original
  reason (decimal divergence on Risk #1) was measured and disproved, but a
  real constraint divergence was found on Risk #6, and the ddev `db` container
  already runs MySQL 8.0 — so the cost of a lane is a connection override, not
  new infrastructure. The exclusion now covers only the *whole suite*; one
  narrow fixture-integrity class may run against MySQL, because nothing else
  can catch a fixture row production would reject. (Source: §4 divergence
  note, as revised.)
- **E2E coverage of the §2 risks** — *added 2026-08-31, when the browser layer
  landed.* The browser suite covers the mobile-first NFR and end-to-end session
  survival only. Risks #2 and #5 stay with the HTTP feature tests of §3 Phases 2
  and 4: a browser asserting the same facts would be slower and flakier for no
  extra signal. If a browser test ever starts asserting a §2 risk, that is
  duplication to delete, not coverage to celebrate. (Source: §4 browser-layer
  note.)
- **A nightly-refresh scheduler test** — no schedule is wired yet (roadmap
  S-04 is `proposed`); testing it now would mean inventing the code under
  test. Re-evaluate when S-04 lands. (Source: Phase 3 challenger pass.)

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-08-31 (§2 response guidance, §4 divergence note, §5 lanes and §7 revised by §3 Phase 1 research and implementation; §4 browser layer and its §7 boundary added the same day when Playwright landed)
- Stack versions last verified: 2026-08-30
- AI-native tool references last verified: 2026-08-30

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes.
