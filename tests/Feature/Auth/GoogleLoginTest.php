<?php

namespace Tests\Feature\Auth;

use App\Enums\OauthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * The path that must never break: an unknown Google account becomes a local user with a session.
 *
 * Everything else in the callback — the returning-user branch and the unverified-email refusal —
 * is verified by hand (see the plan's manual steps); this pins the one flow every login takes.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_google_user_gets_an_account_an_identity_and_a_session(): void
    {
        Socialite::fake(OauthProvider::Google->value, (new SocialiteUser)->map([
            'id' => '110471129847523098411',
            'name' => 'Anna Kowalska',
            'email' => 'anna.kowalska@example.com',
            'user' => ['email_verified' => true],
        ]));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'name' => 'Anna Kowalska',
            'email' => 'anna.kowalska@example.com',
        ]);

        $user = User::query()->where('email', 'anna.kowalska@example.com')->sole();

        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => OauthProvider::Google->value,
            'provider_user_id' => '110471129847523098411',
        ]);

        $this->assertAuthenticatedAs($user);
    }
}
