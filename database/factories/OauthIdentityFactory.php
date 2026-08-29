<?php

namespace Database\Factories;

use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthIdentity>
 */
class OauthIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A caller that already has a User must pass it explicitly (`for($user)` or
     * `['user_id' => $user->id]`) — the default here creates a fresh one, and an identity
     * pointing at the wrong user is a shape login can never produce.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => (string) fake()->unique()->numerify('##################'),
        ];
    }
}
