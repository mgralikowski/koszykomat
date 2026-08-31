<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Test Rollout Phase 1 — Verdict Correctness on Real Leaflet Shapes

- **Plan**: `context/changes/testing-verdict-correctness/plan.md`
- **Scope**: Full plan — Phases 1–5 of 5 (27/27 Progress rows complete)
- **Date**: 2026-08-31
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 7 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Verified independently

- **The oracle is sound.** All ~50 monetary literals across `PromoCalculatorTest` and `BasketComparatorVerdictTest` were recomputed from `lidl-tiles.txt`, the PRD remainder rule and the plain meaning of each Polish promo phrase. Every one agrees. No wrong expectation is pinned as correct in a green test.
- **`app/Pricing/PromoCalculator.php` was not touched.** `git diff 259b661..HEAD --stat -- app/ resources/ routes/ database/migrations/` is empty. Every "What We're NOT Doing" boundary holds.
- **Lane isolation is exactly as claimed.** `#[Group]` appears at exactly four sites; `phpunit.xml` filters nothing. `composer test` 151 passed · `test:all` 7 failed/155 passed · `test:mysql` 11 passed · `--group=known-defect` 7 failed/4 passed.
- **The "turns green by itself" claim is measured, not asserted.** A one-line correction to `conditional()` greens all 11 known-defect tests and leaves all 162 passing. Reverted; tree clean.

## Findings

### F1 — The `known-defect` group swallows the only N>2 coverage of `conditional()`

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: tests/Unit/Pricing/PromoCalculatorTest.php:212, :301
- **Detail**: Four of the ten grouped cases pass today and would still pass after the defect is fixed — `N=3, qty 1` and `N=3, qty 2` for both mechanics. They are the *only* assertions anywhere that exercise `PromoCalculator::conditional()` at `required_quantity > 2`. In the gate every `conditional()` case is N=2, where `$requiredQuantity - 1 == 1` and `$remainder ∈ {0,1}`, so these mutations of `PromoCalculator.php:89-96` survive `composer test` entirely: `$regular->times($remainder)` → `times(min($remainder,1))` (killed only by the excluded `N=3, qty 2`); `$secondItemPrice->times($requiredQuantity - 1)` → `times(1)`; and the `$groups === 0` branch at N>2. The plan warned about exactly this at plan.md:68 — the principle was applied across the N=2/N=3 seam but not *within* the N=3 provider.
- **Fix**: Split the two below-threshold rows out of each `known-defect` provider into an untagged third method per mechanic (`N=3, qty 1 → 4.49`, `qty 2 → 8.98`; and `89.99` / `179.98`), leaving only the genuine divergences tagged.
  - Strength: Restores multi-item-remainder and no-group-fired protection at N>2 to the pre-push gate; keeps the "turns green by itself" property intact; no oracle change.
  - Tradeoff: Three methods per mechanic instead of two — slightly more surface for the same coverage.
  - Confidence: HIGH — the mutation analysis was verified against the source, and both review agents reached it independently.
  - Blind spot: None significant.
- **Decision**: PENDING

### F2 — A shell env var can point `composer test` at the developer's live database

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: composer.json:64, phpunit.xml:27-28, .env:24,27
- **Detail**: `RefreshDatabase` runs `migrate:fresh` → `db:wipe`, dropping every table in whatever `DB_DATABASE` resolves to. `phpunit.xml`'s `<env>` entries carry no `force` attribute, so a shell variable overrides them — which is precisely the mechanism `test:mysql` depends on. `.env` falls back to `DB_CONNECTION="mysql"` / `DB_DATABASE="db"`, the live local dev database. Default `composer test` is safe today, but this change actively teaches contributors to export `DB_CONNECTION`/`DB_DATABASE`, and a lingering export turns a plain `composer test` into a wipe of dev data. `FixtureShapeMySqlTest::setUp()` asserts the driver but never the schema name, so its anti-footgun guard does not cover this.
- **Fix A ⭐ Recommended**: Assert the database name alongside the driver in `FixtureShapeMySqlTest::setUp()`.
  - Strength: Two lines, no config surgery; makes a wrong-schema run loud instead of destructive, and sits next to the guard it completes.
  - Tradeoff: Protects the mysql lane only — a stray export still redirects the SQLite lanes.
  - Confidence: HIGH — mirrors the existing driver assertion exactly.
  - Blind spot: Does not stop a wipe initiated outside this test class.
- **Fix B**: Add `force="true"` to `phpunit.xml`'s DB entries and give `config/database.php` a dedicated `mysql_test` connection with the schema hardcoded.
  - Strength: Closes the hole for every lane; the mysql lane then needs only `DB_CONNECTION=mysql_test`.
  - Tradeoff: Touches shared config; `force="true"` would break the current env-prefix mechanism, so the lane must be migrated in the same change.
  - Confidence: MEDIUM — correct in principle, but the migration has to land atomically or `test:mysql` breaks.
  - Blind spot: Have not verified `force="true"` interacts cleanly with ddev's injected environment.
- **Decision**: PENDING

### F3 — The plan's Open Question is factually wrong; the fix is one line, not a schema change

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/changes/testing-verdict-correctness/plan.md (Open Question), research.md (Open Question)
- **Detail**: Both documents record that fixing `conditional()` requires a schema decision — a new `discounted_quantity` column, or redefining `required_quantity`. Measured during this review: replacing `$regular->plus($secondItemPrice->times($requiredQuantity - 1))` with `$regular->times($requiredQuantity - 1)->plus($secondItemPrice)` greens all 11 known-defect tests and leaves all 162 passing. Every shape the Lidl parser emits (`2+1 gratis`, `Trzeci … za grosz`, classic `1+1`, `drugi za złotówkę`) has exactly one discounted item per group, so the threshold alone suffices. The extra column is needed only for `8 w cenie 4`, which research recorded Lidl printing and which the parser does not model. Left uncorrected, the follow-up change will be scoped as a migration when it is a one-line edit.
- **Fix**: Amend the Open Question in both documents to record the measured one-line fix and to scope the extra column to the unmodelled `N w cenie M` shape.
  - Strength: The follow-up change gets an accurate estimate; the measurement is already done.
  - Tradeoff: None — documentation only.
  - Confidence: HIGH — measured, then reverted, with the full suite green under the candidate fix.
  - Blind spot: Not verified against `8 w cenie 4`, which no parser emits today.
- **Decision**: PENDING

### F4 — `catch (QueryException)` around `parent::setUp()` masks the Risk #6 signal

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Database/FixtureShapeMySqlTest.php:37-47
- **Detail**: `parent::setUp()` runs `migrate:fresh` against MySQL. A migration that works on SQLite and fails on MySQL 8 — utf8mb4 index length, an unsupported default, a type SQLite tolerated — throws `QueryException` here, and that is exactly the divergence this class exists to surface. The catch relabels it "Cannot reach the MySQL test schema. Run `composer test:mysql:setup`". The driver text is appended so it is recoverable, but the headline misdirects toward a setup problem.
- **Fix**: Narrow the catch to connection-level failure (rethrow unless the message indicates an unknown database or access denial).
  - Strength: Keeps the actionable setup message for the case it was written for, while a genuine MySQL-only schema failure surfaces as itself.
  - Tradeoff: String matching on driver messages is brittle across MySQL versions.
  - Confidence: HIGH — the failure path was exercised live during Phase 4.
  - Blind spot: None significant.
- **Decision**: PENDING

### F5 — `expectException(QueryException::class)` is too broad; `$column` is decorative

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Database/FixtureShapeMySqlTest.php:125
- **Detail**: The expectation is satisfied by any `QueryException` in the create path — the Leaflet insert, the Network insert, a future unique-constraint collision, a renamed column. The `$column` value from the provider is never asserted; it only interpolates into the message that fires when nothing throws. The test cannot distinguish "MySQL rejected the out-of-range `required_quantity`" from "something else in the fixture graph failed", which weakens the rejection half — the half that makes the lane non-vacuous.
- **Fix**: Add `expectExceptionMessageMatches("/Out of range value for column '{$column}'/")`.
  - Strength: Makes `$column` load-bearing and pins the rejection to the constraint under test.
  - Tradeoff: Couples the assertion to MySQL's error wording.
  - Confidence: HIGH — the exact message was observed during Phase 4 probing (`SQLSTATE 22003 / 1264`).
  - Blind spot: Message text could change across MySQL 8 point releases.
- **Decision**: PENDING

### F6 — `PromoCalculator`'s docblock documents the contract the new tests refute

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Pricing/PromoCalculator.php:63-69
- **Detail**: The docblock states the rule as "within a complete group the shopper pays the regular price once plus the second-item price for every further item in the group" — the buggy rule. Seven tests now assert the opposite. Nothing in the source flags the contradiction, so a future reader who trusts the docblock will conclude the tests are wrong and "fix" them, erasing the documented defect. This is the one place the deliberate decision not to fix the code leaves a trap.
- **Fix**: Add a comment above the formula pointing at the failing tests and the change that owns the fix. (A comment-only edit — it does not fix the defect.)
  - Strength: Closes the trap at its source, where the next reader will actually be looking.
  - Tradeoff: Touches production code in a phase scoped to tests only, even if only a comment.
  - Confidence: HIGH — the contradiction is plain on reading both files.
  - Blind spot: None significant.
- **Decision**: PENDING

### F7 — Group exclusions live only in composer scripts, so the documented single-file run is red

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: composer.json:54-57, phpunit.xml
- **Detail**: `CLAUDE.md` documents `ddev artisan test tests/Feature/SomeTest.php` for single-file runs. Running the new unit file that way yields 6 failures, and any IDE run configuration hits the same, including the mysql group against SQLite. The filtering exists only inside the composer scripts.
- **Fix**: Move the exclusions into `phpunit.xml` (`<groups><exclude>…`) and let `test:all` / `test:mysql` opt back in with `--group=`.
  - Strength: Any invocation path — CLI, IDE, single file — gets the same safe default.
  - Tradeoff: `test:all` then needs `--group=known-defect` plus the default suite, a slightly less obvious script.
  - Confidence: MEDIUM — the mechanism is standard, but the interaction with `--group` re-inclusion should be verified before relying on it.
  - Blind spot: Not tested against this project's PHPUnit 12 config.
- **Decision**: PENDING

### F8 — Two small plan-vs-shipped gaps

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/changes/testing-verdict-correctness/plan.md:115; tests/Unit/Pricing/PromoCalculatorTest.php:346-354
- **Detail**: (a) plan.md:115 specifies `test:all` → `artisan test` ("everything"); the shipped script excludes the `mysql` group. The deviation is correct — unfiltered gives 18 failures, making the plan's own "fails only in known-defect" criterion unsatisfiable — and it is documented in test-plan §5, but plan.md was never amended the way its other in-flight corrections were. (b) The plan pins the boundary set {1, N−1, N, N+1, 2N}; `conditionalUnitPriceAtThreeCases` covers 2, 3, 4, 6 — qty 1 is absent.
- **Fix**: Amend plan.md:115 to match the shipped lane, and add the `N=3, qty 1 → 6.00` case.
- **Decision**: PENDING

### F9 — Stale "no composite foreign key" claim left in a test docblock

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Database/PriceEntryFactoryTest.php:15-16
- **Detail**: The class docblock still reads "The schema cannot express … no composite foreign key covers it today" — the exact claim `lessons.md` was amended in Phase 5 to correct. The factory's own docblock got the wording right ("no composite foreign key guards it today"). The correction was applied in two of three places.
- **Fix**: Reword to match `lessons.md` — none guards it today, though one is expressible.
- **Decision**: PENDING

### F10 — A `tests/Unit` class boots the application, unlike its siblings

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Unit/Pricing/PromoCalculatorTest.php:33
- **Detail**: The class extends `Tests\TestCase` while `MoneyTest.php:19` in the same tree extends `PHPUnit\Framework\TestCase`. Verified that the app is not needed: with only `vendor/autoload.php`, an unsaved `PriceEntry` with its enum and decimal casts plus `PromoCalculator` returns the correct answer. Cost is ~10 ms per case (0.75 s vs 0.18 s) — not a performance problem, but `tests/Unit` now requires a bootable app, a valid `APP_KEY` and resolvable config, which its siblings deliberately do not.
- **Fix**: Change the parent to `PHPUnit\Framework\TestCase`.
- **Decision**: PENDING
