<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: OAuth Authentication (Google) — Phase 1

- **Plan**: `context/changes/oauth-authentication/plan.md`
- **Scope**: Phase 1 of 3 ("Schema + Socialite wiring"), commit `662f7bc`
- **Date**: 2026-08-29
- **Verdict**: REJECTED at review time → TRIAGE COMPLETE (2026-08-29): 2 fixed, 1 fixed differently, 4 skipped, 1 accepted as risk
- **Findings**: 1 critical, 1 warning, 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

All 10 "Changes Required" items verified as MATCH — no drift, nothing missing, no scope creep.
`routes/web.php` and `resources/views/` are absent from the diff, so Phase 2/3 territory stayed untouched.
All four automated criteria reproduced independently: 19 platform requirements satisfied,
`migrate:fresh --seed` clean, 49 tests / 154 assertions passing, Pint PASS on 66 files.
Both manual criteria carry observable DB evidence (`DESCRIBE users`, `SHOW INDEX FROM oauth_identities`).

## Findings

### F1 — Production schema diverges silently, and the manual reset it depends on has no gate

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality (data safety / reliability)
- **Location**: `database/migrations/0001_01_01_000000_create_users_table.php` + `.github/workflows/deploy.yml:6-8` + `deploy/release.sh:47`
- **Detail**: Not a re-litigation of the in-place edit — that was decided twice and stands. This is about
  whether its consequence is handled. Verified: `deploy.yml` fires on `push: branches: [main]`, and `662f7bc`
  sits on `main`. Pushing *is* the deploy; nothing gates it on the manual reset the plan requires
  "before the first deploy" (`plan.md:347-351`). `release.sh:47` runs `migrate --force`, but
  `0001_01_01_000000_create_users_table` is already recorded in production's `migrations` table, so it is a
  no-op — production keeps `users.password NOT NULL` and keeps `password_reset_tokens`. Nothing detects it:
  the deploy's only assertion is `/_version` matching the SHA, which passes regardless of schema. CI goes
  green on a diverged database, and the failure surfaces in Phase 2 as MySQL error 1364
  ("Field 'password' doesn't have a default value") on the first real Google login.
- **Fix A ⭐ Recommended**: Add a forward migration alongside the new one, e.g.
  `2026_08_29_100001_drop_password_authentication.php`, guarded by `Schema::hasColumn('users', 'password')`
  plus `Schema::dropIfExists('password_reset_tokens')`.
  - Strength: Idempotent — a no-op on a fresh database (which never has the column) and self-healing on the
    existing production one. Keeps the in-place edit exactly as decided; it only removes an ordering
    requirement between a manual step and an automatic deploy that cannot be enforced. No data loss, and it
    dissolves F3 as well.
  - Tradeoff: Two migrations describe one schema decision, which reads slightly redundantly on a fresh clone
    — worth a comment saying the second exists only for the already-deployed database.
  - Confidence: HIGH — the deploy trigger, the `migrate --force` call, and the recorded-migration behaviour
    were each verified directly.
  - Blind spot: Assumes production has actually run migrations at least once. If production's database was
    never migrated, the guard simply no-ops and nothing is lost either way.
- **Fix B**: Keep the manual reset and add an explicit gate — e.g. require `workflow_dispatch` for this
  release, or a pre-deploy check that fails when `users.password` exists.
  - Strength: Preserves the "one migration, one truth" schema story exactly as planned.
  - Tradeoff: Adds CI machinery to protect a single one-off event, and still leaves the reset destructive and
    unrecoverable (no managed backups on the VPS, per CLAUDE.md).
  - Confidence: MEDIUM — workable, but it defends a step that only ever runs once.
  - Blind spot: Have not checked whether the DirectAdmin account has any backup currently enabled.
- **Decision**: ACCEPTED AS RISK — the user will run the reset manually against the remote database. Both
  fixes declined; the in-place edit and the manual step stand as originally decided.
  **Ordering constraint discovered while recording this**: the reset must run *after* the new code is on the
  server, not before. `migrate:fresh` replays the migration files present in the active release, so running it
  against the old release would recreate `password` and `password_reset_tokens`. Correct sequence:
  push (deploy runs, `migrate --force` no-ops) → then `migrate:fresh --seed --force` from the new release.
  Phase 1 adds no routes, so the window between the two is not user-visible; it becomes visible only if
  Phase 2/3 ship before the reset. See F3 for the `--seed` requirement.

### F2 — `getAuthPassword()` returns null; remember-me would emit a PHP deprecation on every login

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (reliability, latent)
- **Location**: `app/Models/User.php:20`
- **Detail**: Verified: `(new App\Models\User)->getAuthPassword()` returns `NULL`, because the
  `Authenticatable` trait reads an attribute that no longer exists. `SessionGuard::queueRecallerCookie()`
  passes that into `hash_hmac()`, which on PHP 8.5 emits
  "Passing null to parameter #2 ($data) of type string is deprecated". This **cannot fire under the current
  plan** — Phase 2 calls `Auth::login($user)` with no remember flag, and the plan's "What We're NOT Doing"
  explicitly excludes remember-me. It becomes real the day someone adds `remember: true`. Everything else is
  clean: `AuthenticateSession` short-circuits on a falsy password and is not registered in `bootstrap/app.php`;
  `logoutOtherDevices` is unreachable without a password flow.
- **Fix**: Override on `User` — `public function getAuthPassword(): string { return ''; }` — with a one-line
  comment saying it exists only so the framework's password-shaped call sites receive a string.
- **Decision**: FIXED — `getAuthPassword(): string { return ''; }` added to `app/Models/User.php` with a
  comment naming the SessionGuard/hash_hmac call site. Verified: returns `""`, 49 tests pass, Pint clean.

### F3 — The production reset must be `migrate:fresh --seed`, or the guest homepage regresses

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (data safety)
- **Location**: `context/changes/oauth-authentication/plan.md:351`
- **Detail**: The plan offers two phrasings for the manual step — "drop the production database" *or*
  `migrate:fresh --seed --force`. Only the second restores FR-001: `HomeController` renders the guest
  comparison entirely from `ExampleBasketSeeder` data, so a bare drop leaves `/` showing "brak danych" until
  someone remembers to run `db:seed`. CLAUDE.md also notes the VPS has no managed backups, so the reset is
  unrecoverable if the "zero real accounts" assumption turns out to be wrong.
- **Fix**: Adopting F1 Fix A deletes this step entirely. Otherwise, narrow the plan's wording to
  `migrate:fresh --seed --force` only, and take a `mysqldump` first.
- **Decision**: FIXED DIFFERENTLY — neither of the offered placements. The user will perform the first
  deploy personally and ensure the database is **empty first**, then let the pipeline's existing
  `migrate --force` build the schema. This is stronger than the reviewed options: with the `migrations`
  table dropped along with everything else, `migrate --force` is no longer a no-op — it replays every
  migration from scratch and produces the correct OAuth-only schema directly. No `migrate:fresh` is
  involved, so the "reset must run after the deploy" ordering constraint recorded under F1 does not apply
  to this approach. `release.sh` does not seed, so `db:seed --force` is still a separate manual step
  afterwards, and FR-001 on the homepage depends on it.

### F4 — `identities()` breaks the repo's model-mirroring relation-naming convention

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Models/User.php:28`
- **Detail**: Every other `HasMany` in the repo mirrors the related model's name —
  `Network::networkProducts()`, `Network::leaflets()`, `NetworkProduct::priceEntries()`,
  `Product::networkProducts()`, `Leaflet::priceEntries()`. `identities()` for `OauthIdentity` is the sole
  exception, and Phase 2/3 call sites (`$user->identities()->where('provider', ...)`) will read against the
  grain of the rest of the app.
- **Fix**: Rename to `oauthIdentities()` now, while there is exactly one call site and zero tests. If
  `identities()` is preferred for readability, that is a defensible disagreement — record it.
- **Decision**: SKIPPED — `identities()` kept. A deliberate disagreement with the repo's
  model-mirroring convention: shorter and reads well on `$user`. Recorded so a future review does not
  re-raise it as an oversight.

### F5 — `provider` is an unconstrained free string where the repo's precedent is a backed enum

- **Severity**: 📋 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: `app/Models/OauthIdentity.php` (no `casts()`), `database/migrations/2026_08_29_100000_create_oauth_identities_table.php:19`
- **Detail**: `PriceEntry` stores `promo_type` as a plain string column and casts it to `App\Enums\PromoType`
  in the model — and `2026_07_25_120005_create_price_entries_table.php:18-21` documents that choice explicitly
  ("enum DDL is MySQL-specific… The model casts it to App\Enums\PromoType"). `OauthIdentity` adopts half of
  the pattern: plain string column, no cast, no enum. `'google'` therefore lives as a bare literal in the
  factory and will reappear as one in the Phase 2 controller and in the unique-lookup query.
- **Fix A ⭐ Recommended**: Accept the plain string as deliberate MVP scoping — one provider, and the roadmap
  parks a second one — but say so in the migration docblock, so the divergence from `PriceEntry` is explained
  rather than accidental.
  - Strength: Zero code, and honest: an enum with one case is ceremony until a second provider exists.
  - Tradeoff: The `'google'` literal stays duplicated across factory, controller, and query.
  - Confidence: HIGH — the PRD parks multi-provider explicitly.
  - Blind spot: If Phase 2 ends up with three or more `'google'` literals, the balance shifts.
- **Fix B**: Add `App\Enums\OauthProvider` with a `'provider' => OauthProvider::class` cast, mirroring `PromoType`.
  - Strength: Consistent with the one existing precedent in this repo; kills the literal.
  - Tradeoff: A new enum file for a single case, before there is a second provider to justify it.
  - Confidence: MEDIUM — clearly correct eventually, arguably premature now.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix B — `app/Enums/OauthProvider.php` added (single `Google` case, backing
  value doubles as the Socialite driver name), cast wired in `OauthIdentity::casts()`, factory switched
  from the `'google'` literal to `OauthProvider::Google`, and the migration docblock now names the
  string-column + enum-cast arrangement it shares with `price_entries.promo_type`. Verified: the value
  round-trips through the database as `enum(OauthProvider::Google)`; 49 tests pass, Pint clean.

### F6 — Callback path duplicated as a literal, with a fallback that masks a misconfigured `APP_URL`

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency / reliability
- **Location**: `config/services.php:23`
- **Detail**: `rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback'` — the path is a
  literal here and will be a second literal in `routes/web.php` in Phase 2, with nothing tying them together.
  Separately, the `'http://localhost'` default means a missing or wrong `APP_URL` in `shared/.env` does not
  fail; it produces a plausible-looking redirect URI that Google rejects with `redirect_uri_mismatch`, which
  is a confusing thing to debug from the Google side.
- **Fix**: In Phase 2, name the route `auth.google.callback` and note in this comment that the literal must
  track it; drop the `env()` default so a misconfigured `APP_URL` surfaces as an obviously empty redirect
  rather than a convincing wrong one.
- **Decision**: SKIPPED — `APP_URL` is reliably set in both environments; the `env()` default and the
  duplicated path literal stand. Phase 2 may still choose to name the route `auth.google.callback`.

### F7 — No index preventing one user accumulating duplicate identities per provider

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture (schema invariants)
- **Location**: `database/migrations/2026_08_29_100000_create_oauth_identities_table.php:24`
- **Detail**: `unique(['provider','provider_user_id'])` correctly stops two users claiming one Google account.
  It does not stop one user holding two Google identities. The plan's linking rule (`plan.md:63-68`) never
  produces that shape, so this is defence-in-depth, not a bug — and adding `unique(['user_id','provider'])`
  would be a real policy choice, since it forecloses "same person, two Google accounts, one Koszykomat
  account". Compare `network_products`, which declares its second uniqueness invariant with a comment
  explaining the limit it accepts.
- **Fix**: Decide explicitly and write the decision into the migration docblock either way; one line saying
  multiple identities per provider are permitted is enough if left open.
- **Decision**: SKIPPED — the migration stays silent on whether one user may hold several identities
  per provider. (A paragraph documenting it was added in error while applying F5 and has been stripped;
  the file is back to silent on this question, as decided.)

### F8 — The new table's invariants are untested

- **Severity**: 📋 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: N/A (`tests/`)
- **Detail**: The phase gate was "the existing 49 tests still pass unchanged", which is the right gate for the
  *removal* and it holds. But nothing exercises the *addition*: no test touches `oauth_identities`,
  `OauthIdentityFactory`, the unique index, the cascade, or `User::identities()`. Table creation is covered
  incidentally by `RefreshDatabase`; the invariants are not. Per CLAUDE.md, tests are optional in MVP outside
  the four promo mechanics, so this is not a convention violation — and all four behaviours were verified
  manually against the SQLite test connection (unique enforced, FK enforced, cascade deletes, correct DDL).
  Flagged only so "49 tests still pass" is not mistaken for coverage of the new table.
- **Fix**: None required — Phase 2's happy-path test will exercise the insert path. Note it and move on.
- **Decision**: SKIPPED — Phase 2's happy-path OAuth test exercises the insert path, and CLAUDE.md makes
  broader coverage optional during MVP. The four invariants (unique index, FK, cascade, DDL) were verified
  by hand against the SQLite test connection during this review.

## Clean findings (no action)

- **Security** — no secrets in the diff; `.env.example` ships empty placeholders, `.env` is gitignored and
  untracked. Phase 1 adds no routes or request handling, so no authn/authz boundary and no injection surface.
  Socialite v5.30.1 resolves `services.google`, matching the new config key exactly.
- **Removed `auth.passwords` / `auth.password_timeout`** — traced every framework read.
  `PasswordResetServiceProvider` is deferred, so the missing key never throws at boot;
  `RequirePassword::__construct` degrades a missing timeout to its 10800 default. Dormant edge:
  `Illuminate\Foundation\Auth\User` still composes `CanResetPassword`, so `sendPasswordResetNotification()`
  remains callable and would throw "Password resetter [] is not defined" — nothing calls it.
- **New schema correctness** — verified on the actual SQLite test connection: unique index enforced, FK
  enforced, cascade delete works, `password_reset_tokens` absent. MySQL index key length 2040 bytes, under
  InnoDB's 3072-byte DYNAMIC limit.
- **Residual references** — none. The only `password` hits repo-wide are `DB_PASSWORD` / `REDIS_PASSWORD` /
  `MAIL_PASSWORD` / `MEMCACHED_PASSWORD` and the three new explanatory comments.
- **Lessons register** — both entries respected. `OauthIdentityFactory` has a single parent and its docblock
  warns callers to pass their own user; no Blade was touched.
- **Language convention** — all new comments and docblocks are English; no user-facing strings added this
  phase, so nothing needed Polish.
