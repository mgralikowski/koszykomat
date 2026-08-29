<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OauthProvider;
use App\Http\Controllers\Controller;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * The only way into the application (PRD FR-002): hand off to Google, then turn what comes back
 * into a session.
 *
 * A user is identified by (provider, provider_user_id), never by email — an email change at the
 * provider must not orphan an account. Email decides one thing only: whether an incoming identity
 * attaches to an account that already exists.
 */
class GoogleController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver(OauthProvider::Google->value)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        // Google reports a declined consent screen as ?error=access_denied with no code. Reading it
        // here keeps the ordinary "user changed their mind" case out of the catch below, which is
        // for genuine failures worth reporting.
        if ($request->has('error')) {
            return redirect('/')->with('auth_error', 'Logowanie przez Google zostało przerwane.');
        }

        try {
            $googleUser = Socialite::driver(OauthProvider::Google->value)->user();
        } catch (Throwable $e) {
            // An expired session invalidates the OAuth state; so does a network or token failure.
            // None of them should reach the user as a stack trace.
            report($e);

            return redirect('/')->with('auth_error', 'Nie udało się zalogować przez Google. Spróbuj ponownie.');
        }

        $user = $this->resolveUser($googleUser);

        if ($user === null) {
            return redirect('/')->with(
                'auth_error',
                'Konto z tym adresem e-mail już istnieje, a Google nie potwierdziło tego adresu. Zaloguj się w sposób użyty poprzednio.'
            );
        }

        Auth::login($user);

        // Without this the pre-login session id survives the privilege change — session fixation.
        $request->session()->regenerate();

        // A guest bounced off a gated route (the basket) comes back to it rather than to the
        // homepage. The failure branches above deliberately keep their literal redirect('/'):
        // a login that did not succeed must land where its message can be read, not re-enter
        // the gate that would send it straight back to Google.
        return redirect()->intended('/');
    }

    /**
     * Resolve the provider's account into a local user, or null when the login must be refused.
     */
    private function resolveUser(SocialiteUser $googleUser): ?User
    {
        $providerUserId = (string) $googleUser->getId();

        $identity = OauthIdentity::query()
            ->where('provider', OauthProvider::Google)
            ->where('provider_user_id', $providerUserId)
            ->first();

        // Known identity — the returning-user path, and the only one that ignores email entirely.
        if ($identity !== null) {
            return $identity->user;
        }

        $email = $googleUser->getEmail();

        // No email means no way to decide whether this is a new person or an existing one.
        if (blank($email)) {
            return null;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing === null) {
            return DB::transaction(function () use ($googleUser, $email, $providerUserId): User {
                $user = User::create([
                    'name' => $this->displayName($googleUser, $email),
                    'email' => $email,
                ]);

                $user->identities()->create([
                    'provider' => OauthProvider::Google,
                    'provider_user_id' => $providerUserId,
                ]);

                return $user;
            });
        }

        // The email is already taken by another identity. Attaching on an unverified address would
        // hand over that account to anyone who can claim the address at a laxer provider. Google
        // always verifies, so this refusal is unreachable today — it guards the second provider.
        if (! $this->emailIsVerified($googleUser)) {
            return null;
        }

        $existing->identities()->create([
            'provider' => OauthProvider::Google,
            'provider_user_id' => $providerUserId,
        ]);

        return $existing;
    }

    /**
     * Whether the provider vouches for the address it just handed us.
     *
     * Not exposed by Socialite's user object: the flag lives in the raw payload, under a key that
     * differs between Google's OpenID userinfo response and the older People shape.
     */
    private function emailIsVerified(SocialiteUser $googleUser): bool
    {
        $raw = $googleUser->user ?? [];

        return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
    }

    /**
     * `users.name` is NOT NULL, but Google only returns a name when the profile carries one.
     */
    private function displayName(SocialiteUser $googleUser, string $email): string
    {
        return $googleUser->getName()
            ?: $googleUser->getNickname()
            ?: Str::before($email, '@');
    }
}
