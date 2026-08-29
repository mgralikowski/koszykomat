# OAuth Authentication (Google) Implementation Plan

## Overview

Wire Google OAuth as the **only** way to authenticate into Koszykomat: a redirect/callback flow via Laravel Socialite, provider identities stored in their own `oauth_identities` table, a `users` table reshaped as if it never had passwords, and header chrome that makes login discoverable from the guest homepage.

This is roadmap item **F-02** — a foundation, not a feature. On its own it delivers no user-visible value beyond "I am logged in"; it exists to unlock **S-02** (basket builder behind auth) and **S-03** (per-account saved baskets).

## Current State Analysis

Authentication is genuinely absent — there is nothing to adapt, only to build.

- **No auth packages.** `composer.json` requires `laravel/framework:^13.8` and `laravel/tinker` only. No Socialite, Breeze, Fortify, or Jetstream.
- **The `users` table contradicts the PRD.** `database/migrations/0001_01_01_000000_create_users_table.php:19` declares `password` as NOT NULL, and the same migration creates `password_reset_tokens`. The PRD (`§Non-Goals`, `§Access Control`) rules out email+password entirely. The NOT NULL column would hard-fail the first OAuth user insert.
- **`config/auth.php:20`** still wires the `users` password broker (`'passwords' => env('AUTH_PASSWORD_BROKER', 'users')`) — dead configuration under OAuth-only.
- **No place for a login link.** `resources/views/layouts/app.blade.php` is a bare shell: `<head>` + `<body>` + `@yield('content')`. No header, no nav.
- **Only two routes exist.** `routes/web.php` holds `/` → `HomeController` and the `/_version` deploy probe.
- **Session auth is ready.** `.env.example` sets `SESSION_DRIVER=database` and the `sessions` table is already migrated — no extra setup needed for a session guard.
- **`config/services.php`** has no OAuth block; `.env.example` has no OAuth variables.
- **Production secrets are hand-filled.** Per `context/deployment/deploy-plan.md:70`, prod `.env` lives at `~/domains/koszykomat.pl/shared/.env` and is edited manually — new OAuth variables will **not** arrive via CI.
- **Deploys run migrations.** `deploy/release.sh:47` runs `artisan migrate --force` on every release.
- **Two locales in play.** `config/app.php:70` is `Europe/Warsaw`, `config/app.php:83` locale defaults to `en` (prod `.env` sets `pl`). Per CLAUDE.md, user-facing strings are Polish, code/comments English.

### Key Discoveries

- **Socialite in Laravel 13 ships a first-class test fake**: `Socialite::fake('google', $user)` — no Mockery gymnastics needed for the happy-path test.
- **The facade namespace changed.** Laravel 13 docs use `use Laravel\Socialite\Socialite;`, **not** the older `Laravel\Socialite\Facades\Socialite`. Muscle memory will reach for the wrong one.
- **Google's verified-email flag is not a first-class accessor.** `Socialite\Two\User` exposes `getId()/getName()/getEmail()/getAvatar()`; the verification flag lives only in the raw `->user` array. Google's OpenID `userinfo` returns `email_verified`; the older People shape returned `verified_email`.
- **Dropping `password` is safe for session auth.** `Auth::login($user)` never calls `getAuthPassword()` — only credential-based `Auth::attempt()` and the opt-in `AuthenticateSession` middleware do, and neither is used here.
- **Existing fixtures depend on `password`.** `database/factories/UserFactory.php:29` hashes a password, and `database/seeders/DatabaseSeeder.php:22` creates a `test@example.com` user through that factory. Both must move in lockstep with the schema.
- **`app/Models/User.php:13,29`** declares `password` in `#[Fillable]` and casts it as `hashed` — both must go.
- **Lessons register applies.** `context/foundation/lessons.md`: (1) never let related factories each create their own parent — the `OauthIdentity` factory must derive its user rather than spawn one independently where a test supplies one; (2) Blade must use the `@php … @endphp` block form, never `@php(...)`.

## Desired End State

A visitor lands on the homepage and sees a header with **„Zaloguj się"**. Clicking it sends them to Google, and returning drops them back on the homepage — now with their name and **„Wyloguj"** in the header, and a `users` row plus a linked `oauth_identities` row in the database. Logging in again with the same Google account reuses that user. There is no password field anywhere in the application, and no way to authenticate other than Google.

Verified by: the full test suite green (including a new happy-path OAuth feature test), `artisan migrate:fresh --seed` applying cleanly, and one manual round-trip through real Google in ddev.

## What We're NOT Doing

- **No second provider.** Google only. The `oauth_identities` shape leaves room for more, but no other driver is configured.
- **No protected pages.** S-02 owns the basket builder and its `auth` middleware gate. This change adds no gated route and no placeholder page.
- **No account management.** No profile editing, no account deletion, no unlinking an identity, no admin panel (PRD `§Non-Goals`).
- **No email+password, no password reset, no email verification flow, no email sending at all** (PRD `§Non-Goals` — OAuth was chosen precisely to avoid mail).
- **No token persistence.** Access/refresh tokens are not stored; nothing in the MVP calls a Google API on the user's behalf.
- **No "remember me".** The default session lifetime (`SESSION_LIFETIME=120`) stands.
- **No test coverage beyond the happy path.** The linking and refusal branches are implemented and manually verified, not automated (per CLAUDE.md: tests optional in MVP outside the four promo mechanics).
- **No automated production database reset.** That is a manual, user-owned step — see Migration Notes.

## Implementation Approach

Three phases, ordered so the riskiest change gets its own checkpoint.

The users-table surgery lands **first and alone** (Phase 1). Removing a column that four different files reference is the one place this change can quietly break something unrelated, and the existing ~30-test suite is a real regression net for it — but only if nothing else is changing at the same time. Phase 1 is therefore invisible to users and gated purely on "everything that passed before still passes".

Phase 2 adds the flow with no chrome: routes, controller, the linking rule. It is testable by hitting the callback route directly with `Socialite::fake`, so it does not need a UI to be verified.

Phase 3 adds the header and the Polish strings, and is the only phase requiring a real Google round-trip.

**Identity model.** A user is identified by `(provider, provider_user_id)` in `oauth_identities`, not by email. Email is used for one thing only: deciding whether an incoming identity should attach to an existing account.

**Linking rule.** On callback:
1. Known `(provider, provider_user_id)` → log in that identity's user. Done.
2. Unknown identity, email not in `users` → create the user, create the identity, log in.
3. Unknown identity, email already in `users` → attach the identity to that user **only if the provider reports the email verified**; otherwise refuse and return to the homepage with a Polish error message.

Case 3 is unreachable while Google is the only provider (Google always verifies, and a matching email means it is the same Google account, which case 1 already caught). It is implemented anyway because the rule must live next to the code it guards — by the time a second provider is added, this decision would be far away and easy to get wrong.

## Critical Implementation Details

**Socialite namespace.** Import `Laravel\Socialite\Socialite` — Laravel 13's namespace. The older `Laravel\Socialite\Facades\Socialite` is the wrong reach here.

**Verified-email lookup.** The flag is not on the `Socialite\Two\User` object; it is in the raw payload under a key that differs by Google endpoint shape. Read both:

```php
$raw = $socialiteUser->user;
$emailVerified = (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
```

**Two redirect URIs.** The Google Cloud console entry needs *both* `https://koszykomat.ddev.site/auth/google/callback` and `https://koszykomat.pl/auth/google/callback` registered, or local and production cannot both work from one client ID.

**Ordering within Phase 1.** Edit the `users` migration, then `User`, then `UserFactory`, then `DatabaseSeeder`, then `config/auth.php` — and run `artisan migrate:fresh --seed` before running the suite. Running the suite against a stale local database will produce confusing failures unrelated to the change.

## Phase 1: Schema + Socialite wiring

### Overview

Install Socialite, add its Google configuration, and reshape the `users` table as if the application never had passwords. No user-visible change. The gate is that everything which passed before still passes.

### Changes Required

#### 1. Socialite dependency

**File**: `composer.json`

**Intent**: Add Laravel Socialite so the Google driver is available.

**Contract**: `ddev composer require laravel/socialite` — let Composer resolve the current stable release compatible with `laravel/framework:^13.8` and `php:^8.5`. Do not hand-pin a version. Verify with `ddev composer check-platform-reqs` afterwards.

#### 2. Google service configuration

**File**: `config/services.php`

**Intent**: Register the Google OAuth credentials block alongside the existing third-party service entries.

**Contract**: A `'google'` key with `client_id`, `client_secret`, and `redirect`, all sourced from env. The redirect must be absolute and derived from `APP_URL` rather than hard-coded, so local and production resolve differently without a config edit.

#### 3. Environment variables

**File**: `.env.example` (and the developer's local `.env`)

**Intent**: Document the two new secrets so a fresh clone — and the production `shared/.env` — knows what to fill.

**Contract**: `GOOGLE_CLIENT_ID=` and `GOOGLE_CLIENT_SECRET=`, added near the other credential blocks, both empty in the example file.

#### 4. Users table reshaped as OAuth-only

**File**: `database/migrations/0001_01_01_000000_create_users_table.php`

**Intent**: Remove every trace of password authentication from the original schema, so the migration reads as though the product was always OAuth-only. Edited in place — a deliberate choice, viable only because production carries no real user data yet (see Migration Notes).

**Contract**: Drop the `password` column and the entire `password_reset_tokens` table (both its `Schema::create` and its `dropIfExists`). `remember_token`, `email_verified_at`, and the unique `email` stay — `email` uniqueness is load-bearing for the linking rule.

#### 5. OAuth identities table

**File**: `database/migrations/<timestamp>_create_oauth_identities_table.php` (new)

**Intent**: Store which external identity maps to which local user, so a second provider later needs no schema change or backfill.

**Contract**: `id`, `user_id` (foreign key to `users`, cascade on delete), `provider` (string), `provider_user_id` (string), timestamps. A unique composite index on `(provider, provider_user_id)` — this is the lookup key and the guard against two users claiming one Google account. Follow the migration style already established in `database/migrations/2026_07_25_120*`.

#### 6. OauthIdentity model + factory

**Files**: `app/Models/OauthIdentity.php`, `database/factories/OauthIdentityFactory.php` (both new)

**Intent**: Give the identity table an Eloquent model with its `belongsTo` user, and a factory for fixtures.

**Contract**: `OauthIdentity::user(): BelongsTo`. Follow the attribute-based style used by the F-01 models (`app/Models/PriceEntry.php` et al.) rather than the older `protected $fillable` convention.

Per `context/foundation/lessons.md` ("Never let related factories each create their own parent"): the factory's default `user_id` may create a `User`, but any test or seeder that supplies its own user must pass it explicitly — never let a caller end up with an identity pointing at a user other than the one it means.

#### 7. User model cleanup

**File**: `app/Models/User.php`

**Intent**: Remove password from the model surface and expose the identities relation.

**Contract**: Drop `'password'` from `#[Fillable]` and drop the `'password' => 'hashed'` cast; keep the `email_verified_at` cast. Drop `'password'` from `#[Hidden]`. Add `identities(): HasMany` to `OauthIdentity`.

#### 8. UserFactory cleanup

**File**: `database/factories/UserFactory.php`

**Intent**: Stop producing a column that no longer exists.

**Contract**: Remove the `password` key and the `static::$password` property along with the now-unused `Hash` import. Keep `name`, `email`, `email_verified_at`, `remember_token`, and the `unverified()` state.

#### 9. Password broker removal

**File**: `config/auth.php`

**Intent**: Delete configuration for a feature the product does not have.

**Contract**: Remove the `'passwords'` default and the `passwords` array. Leave `guards.web` (session driver) and `providers.users` untouched — those are what OAuth login uses.

#### 10. Seeder alignment

**File**: `database/seeders/DatabaseSeeder.php`

**Intent**: Keep the guarded `test@example.com` fixture working after the factory change.

**Contract**: The existence-guarded `User::factory()->create([...])` block at line 22 stays; only verify it passes no password. No new seeding is required — an OAuth identity fixture is not needed for the seed.

### Success Criteria

#### Automated Verification

- Socialite installed and platform requirements satisfied: `ddev composer check-platform-reqs`
- Migrations apply from scratch: `ddev artisan migrate:fresh --seed`
- Full test suite passes unchanged: `ddev composer test`
- Code style passes: `ddev composer lint`
- No `password` reference remains outside vendor: `grep -rn "password" app/ database/ config/auth.php --exclude-dir=vendor` returns only `GOOGLE_CLIENT_SECRET`-adjacent or unrelated matches

#### Manual Verification

- `users` table in the local database has no `password` column and `password_reset_tokens` no longer exists
- `oauth_identities` carries the unique `(provider, provider_user_id)` index (`SHOW INDEX FROM oauth_identities`)

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation before proceeding.

---

## Phase 2: Login flow

### Overview

The redirect/callback routes, the controller, the linking rule, and logout — with no UI. Verified by driving the callback directly with `Socialite::fake`.

### Changes Required

#### 1. Authentication routes

**File**: `routes/web.php`

**Intent**: Expose the three endpoints the flow needs.

**Contract**: `GET /auth/google/redirect` → redirect action; `GET /auth/google/callback` → callback action; `POST /logout` → logout action. Logout is POST so it is CSRF-protected and cannot be triggered by a stray link or prefetch. Named routes so the header can reference them. Place them above the `/_version` probe, which stays last.

#### 2. OAuth login controller

**File**: `app/Http/Controllers/Auth/GoogleController.php` (new)

**Intent**: Own the redirect to Google and the callback that resolves an identity into a session.

**Contract**: Two actions — `redirect()` returning `Socialite::driver('google')->redirect()`, and `callback()` implementing the three-case linking rule from Implementation Approach, then `Auth::login($user)`, `$request->session()->regenerate()`, and a redirect to `/`.

Session regeneration after login is not optional — without it the pre-login session ID survives, which is a session-fixation hole.

The callback must survive a user who declines consent or an aborted flow: Socialite throws when the provider returns an error instead of a code. Catch it and return to `/` with a Polish message rather than a 500.

**Contract (verified-email check)** — the one non-obvious read, since the flag is absent from the `Socialite\Two\User` accessors:

```php
$raw = $socialiteUser->user;
$emailVerified = (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
```

#### 3. Logout action

**File**: `app/Http/Controllers/Auth/LogoutController.php` (new, or a third action on `GoogleController` if it reads more naturally)

**Intent**: End the session cleanly.

**Contract**: `Auth::logout()`, then session invalidate and token regenerate, then redirect to `/`. Logging out of Koszykomat does not log the user out of Google — that is correct and expected.

#### 4. Happy-path feature test

**File**: `tests/Feature/Auth/GoogleLoginTest.php` (new)

**Intent**: Pin the one path that must never break — an unknown Google user gets an account, an identity, and a session.

**Contract**: Uses `Socialite::fake('google', ...)` with a `Laravel\Socialite\Two\User` carrying id, name, email, and `email_verified => true` in the raw payload. Hits `GET /auth/google/callback`, asserts a redirect to `/`, asserts the `users` and `oauth_identities` rows exist, and asserts `$this->assertAuthenticated()`. Follow the structure of the existing `tests/Feature/HomePageTest.php`.

### Success Criteria

#### Automated Verification

- Full test suite passes: `ddev composer test`
- The new OAuth test passes: `ddev artisan test tests/Feature/Auth/GoogleLoginTest.php`
- Code style passes: `ddev composer lint`
- Routes registered as expected: `ddev artisan route:list --except-vendor` shows the three new routes

#### Manual Verification

- Hitting `/auth/google/redirect` in a browser lands on Google's consent screen with the correct app name and scopes
- Returning from Google creates exactly one `users` row and one `oauth_identities` row
- Logging in a second time with the same Google account creates **no** new rows and reuses the existing user
- Declining consent at Google returns to `/` with a Polish message, not a stack trace
- `POST /logout` ends the session; the homepage still renders for the now-guest

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation before proceeding.

---

## Phase 3: Header + Polish UI

### Overview

Make login discoverable and logged-in state visible. This is the first shared chrome in the application — S-02 will hang the basket link on it, so it should be built to be extended, not as a one-off.

### Changes Required

#### 1. Shared header

**File**: `resources/views/layouts/app.blade.php`

**Intent**: Add a header bar above `@yield('content')` showing „Zaloguj się" for guests and the user's name plus „Wyloguj" when authenticated.

**Contract**: A `<header>` element inside `<body>`, before the content yield, styled to match the existing Tailwind vocabulary in `resources/views/home.blade.php` (`max-w-3xl` container, `text-slate-*` scale, `ring-1 ring-slate-200` surfaces). Guest state links to the named redirect route; authenticated state renders a `POST` form to the logout route with `@csrf`.

Must remain usable single-column at 375 px, per the mobile-first NFR that `home.blade.php` already satisfies.

Per `context/foundation/lessons.md`: use the `@php … @endphp` block form if any inline PHP is needed — `@php(...)` is removed in Laravel 11+.

#### 2. Error message surface

**File**: `resources/views/layouts/app.blade.php` (same header region or just below it)

**Intent**: Render the Polish error message set by the callback's refusal and abort branches.

**Contract**: A conditional flash-message block reading the session key the controller writes. Polish copy, per CLAUDE.md — the declined-consent case and the unverified-email refusal each need their own sentence.

#### 3. Homepage integration check

**File**: `resources/views/home.blade.php`

**Intent**: Ensure the existing `<header>` inside `<main>` does not collide visually or semantically with the new layout-level header.

**Contract**: If two `<header>` elements stack awkwardly, demote the in-page one to a `<div>` or adjust spacing. No content or copy changes to the comparison itself.

### Success Criteria

#### Automated Verification

- Full test suite passes: `ddev composer test`
- Frontend builds: `ddev npm run build`
- Code style passes: `ddev composer lint`
- Homepage test still passes: `ddev artisan test tests/Feature/HomePageTest.php`

#### Manual Verification

- Guest homepage shows „Zaloguj się"; a full real-Google round-trip in ddev returns to the homepage showing the user's name and „Wyloguj"
- „Wyloguj" returns the header to its guest state
- Header is usable single-column at 375 px with no horizontal scroll
- All new strings render in correct Polish with proper diacritics
- The comparison report on the homepage is visually unaffected

---

## Testing Strategy

### Unit Tests

None. There is no pure logic here worth isolating — the linking rule is inseparable from the HTTP callback and the database.

### Integration Tests

- **Happy path (automated)**: unknown Google identity → user created, identity created, session established, redirect to `/`.

### Manual Testing Steps

1. Register the OAuth client in Google Cloud with **both** redirect URIs (ddev and production).
2. Fill `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in the local `.env`.
3. Visit `https://koszykomat.ddev.site`, click „Zaloguj się", complete Google consent → expect a return to the homepage with the name in the header.
4. Verify one `users` row and one `oauth_identities` row.
5. Click „Wyloguj", then log in again → expect the same user, no new rows.
6. Repeat step 3 but click **Cancel** at Google → expect a Polish message on the homepage, no exception.
7. Resize to 375 px and confirm the header stays single-column.

## Performance Considerations

None meaningful. The callback performs one indexed lookup on `(provider, provider_user_id)`, at most one lookup on `users.email`, and at most two inserts. The `<2 s` NFR applies to basket comparison, not to a once-per-session redirect whose latency is dominated by Google.

## Migration Notes

**A manual production database reset is required before the first deploy of this change — this step is owned by the user.**

`database/migrations/0001_01_01_000000_create_users_table.php` is edited in place. Because that migration is already recorded in production's `migrations` table, `deploy/release.sh:47`'s `artisan migrate --force` will **not** re-apply it. Without the reset, production keeps `password` NOT NULL and the first real Google login fails on a constraint violation.

The user will drop the production database (or run `artisan migrate:fresh --seed --force` on the VPS) manually before deploying this change. This is safe only because production currently holds seeded demo data and zero real user accounts — **and this is the last change for which that will be true.** S-03 introduces real saved baskets; from that point on, migrations must be forward-only.

Also required on the server before the first login attempt: `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` added to `~/domains/koszykomat.pl/shared/.env` by hand (CI does not carry secrets into that file), and `https://koszykomat.pl/auth/google/callback` registered as an authorized redirect URI in the Google Cloud console.

Rollback: revert the commits and re-run the reset. There is no data worth preserving in this change.

## References

- Roadmap item F-02: `context/foundation/roadmap.md`
- Access model: `context/foundation/prd.md` §Access Control, FR-002, §Non-Goals
- Recurring rules: `context/foundation/lessons.md`
- Deploy + secrets handling: `context/deployment/deploy-plan.md:43-87`
- Model style to follow: `app/Models/PriceEntry.php`
- Feature-test style to follow: `tests/Feature/HomePageTest.php`
- View vocabulary to follow: `resources/views/home.blade.php`
- Laravel 13 Socialite docs (facade namespace, `Socialite::fake`): https://github.com/laravel/docs/blob/13.x/socialite.md

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Schema + Socialite wiring

#### Automated

- [x] 1.1 Socialite installed and platform requirements satisfied: `ddev composer check-platform-reqs` — 662f7bc
- [x] 1.2 Migrations apply from scratch: `ddev artisan migrate:fresh --seed` — 662f7bc
- [x] 1.3 Full test suite passes unchanged: `ddev composer test` — 662f7bc
- [x] 1.4 Code style passes: `ddev composer lint` — 662f7bc
- [x] 1.5 No `password` reference remains outside vendor — 662f7bc

#### Manual

- [x] 1.6 `users` has no `password` column and `password_reset_tokens` no longer exists — 662f7bc
- [x] 1.7 `oauth_identities` carries the unique `(provider, provider_user_id)` index — 662f7bc

### Phase 2: Login flow

#### Automated

- [x] 2.1 Full test suite passes: `ddev composer test` — 629c340
- [x] 2.2 The new OAuth test passes: `ddev artisan test tests/Feature/Auth/GoogleLoginTest.php` — 629c340
- [x] 2.3 Code style passes: `ddev composer lint` — 629c340
- [x] 2.4 Routes registered as expected: `ddev artisan route:list --except-vendor` — 629c340

#### Manual

- [x] 2.5 `/auth/google/redirect` lands on Google's consent screen with the correct app name and scopes — 629c340
- [x] 2.6 Returning from Google creates exactly one `users` row and one `oauth_identities` row — 629c340
- [x] 2.7 Second login with the same account creates no new rows and reuses the user — 629c340
- [x] 2.8 Declining consent returns to `/` with a Polish message, not a stack trace — 629c340
- [x] 2.9 `POST /logout` ends the session; the homepage still renders for the guest — 629c340

### Phase 3: Header + Polish UI

#### Automated

- [x] 3.1 Full test suite passes: `ddev composer test`
- [x] 3.2 Frontend builds: `ddev npm run build`
- [x] 3.3 Code style passes: `ddev composer lint`
- [x] 3.4 Homepage test still passes: `ddev artisan test tests/Feature/HomePageTest.php`

#### Manual

- [x] 3.5 Guest header shows „Zaloguj się"; a real-Google round-trip returns showing the name and „Wyloguj"
- [x] 3.6 „Wyloguj" returns the header to its guest state
- [x] 3.7 Header usable single-column at 375 px with no horizontal scroll
- [x] 3.8 All new strings render in correct Polish with proper diacritics
- [x] 3.9 The comparison report on the homepage is visually unaffected
