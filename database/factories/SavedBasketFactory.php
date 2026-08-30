<?php

namespace Database\Factories;

use App\Models\SavedBasket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedBasket>
 */
class SavedBasketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A caller that already has a User must pass it explicitly (`for($user)` or
     * `['user_id' => $user->id]`) — the default creates a fresh one, and a basket saved under the
     * wrong account is the exact shape the privacy NFR forbids.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Zakupy testowe',
        ];
    }
}
