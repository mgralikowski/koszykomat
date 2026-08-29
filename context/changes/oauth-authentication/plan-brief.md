# OAuth Authentication (Google) — Plan Brief

> Full plan: `context/changes/oauth-authentication/plan.md`

## What & Why

Wire Google OAuth as the only way to log into Koszykomat. This is roadmap item **F-02** — a foundation, not a feature: on its own it only gets a user logged in, but it is the gate that unlocks **S-02** (basket builder) and **S-03** (per-account saved baskets), which together are the rest of the MVP's user-facing path. The PRD chose OAuth-only specifically to avoid sending any email.

## Starting Point

Auth is genuinely absent — no Socialite, no Breeze, no Fortify; `composer.json` has only the framework and Tinker. Worse, the default `users` table actively contradicts the PRD: `password` is NOT NULL and `password_reset_tokens` exists, so the first OAuth insert would fail outright. There is also no header anywhere in `layouts/app.blade.php`, so there is currently no place to put a login link.

## Desired End State

A visitor sees „Zaloguj się" in a header on the homepage, clicks through to Google, and returns to the same homepage — now showing their name and „Wyloguj". A `users` row and a linked `oauth_identities` row exist; logging in again reuses them. No password field exists anywhere in the application.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Provider scope | Google only | Fastest path to a working login and the PRD's named example; broadest coverage of the Polish consumer audience. |
| Identity storage | Separate `oauth_identities` table | A second provider later needs no migration or backfill — and by then S-03 will have hung real saved baskets off `users`. |
| Email collision | Link only when the provider reports the email verified, else refuse | Blocks the classic takeover-by-unverified-email attack while keeping the happy path seamless; unreachable today, but the rule must live next to the code it guards. |
| Users table surgery | Edit the original migration in place | Cleanest end state — the schema reads as if the product was always OAuth-only, with no vestigial nullable column. |
| Production migration | Manual DB reset before deploy, user-owned | Deploys run `migrate --force`, which never re-applies an edited migration; safe only because prod holds zero real users — and this is the last change for which that is true. |
| Post-login landing | Back to `/`, with new header chrome | No orphan placeholder page, and the header is exactly what S-02 will hang the basket link on. |
| Test depth | Happy path only, via `Socialite::fake` | CLAUDE.md makes tests optional in MVP outside the promo mechanics; the linking and refusal branches are verified manually. |

## Scope

**In scope:** Socialite install + Google config; `users` reshaped as OAuth-only (password and reset tokens removed); `oauth_identities` table, model, factory; redirect/callback/logout routes and controller; the three-case linking rule; one happy-path feature test; header chrome with Polish login/logout strings.

**Out of scope:** any second provider; any auth-gated page (S-02 owns that); profile management, account deletion, identity unlinking; email+password, password reset, email verification, any mail; token persistence; "remember me"; automated tests beyond the happy path; any automated production database reset.

## Architecture / Approach

`GET /auth/google/redirect` hands off to Socialite; `GET /auth/google/callback` resolves the returned identity through three cases — known `(provider, provider_user_id)` logs straight in; unknown identity with a fresh email creates user + identity; unknown identity with a taken email attaches only if the provider verified that email, otherwise refuses. Then `Auth::login()`, session regenerate, back to `/`. Users are identified by provider identity, never by email; email is consulted solely to decide account linking.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Schema + Socialite wiring | Socialite installed and configured; `users` reshaped; `oauth_identities` created | Dropping `password` touches the model, factory, seeder, and auth config — the one place this change can quietly break something unrelated. Gated on the existing suite staying green with nothing else in flight. |
| 2. Login flow | Routes, controller, linking rule, logout, happy-path test | Session fixation if the session is not regenerated after login; a declined consent throwing a 500 instead of a message. |
| 3. Header + Polish UI | Login/logout chrome, Polish strings, error surface | First shared chrome in the app — S-02 will build on it, so a one-off would cost later. Requires a real Google round-trip to verify. |

**Prerequisites:** A Google Cloud OAuth client with **both** redirect URIs registered (`https://koszykomat.ddev.site/...` and `https://koszykomat.pl/...`), and the client ID/secret in the local `.env`. For deploy: the same two values hand-added to `~/domains/koszykomat.pl/shared/.env`, plus the manual production database reset.

**Estimated effort:** ~1–2 sessions across three phases.

## Open Risks & Assumptions

- **The production reset must not be forgotten.** An edited migration never re-applies via `migrate --force`; skipping the reset ships a guaranteed 500 on the first production login. Owned by the user, called out in Migration Notes.
- **This is the last cheap schema rewrite.** S-03 introduces real saved baskets; from then on migrations must be forward-only.
- **Google-only is a real access constraint.** Anyone without a Google account cannot use the product at all — acceptable for MVP validation, worth revisiting if signups stall.
- **The linking branch ships unexercised.** It is unreachable with a single verified provider, so it will get its first real test the day a second provider is added.

## Success Criteria (Summary)

- A guest can log in with Google from the homepage and comes back recognized, with their name in the header.
- Logging in twice with the same Google account yields one user, not two.
- No password exists anywhere in the schema, the models, the factories, or the config.
