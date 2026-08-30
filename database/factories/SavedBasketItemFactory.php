<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SavedBasket;
use App\Models\SavedBasketItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedBasketItem>
 */
class SavedBasketItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The two parents are genuinely independent here — a saved basket and a canonical product
     * share no ancestor — so building both by factory cannot produce the mismatched shape that
     * PriceEntryFactory has to guard against. A caller pinning one of them should pin it
     * explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'saved_basket_id' => SavedBasket::factory(),
            'product_id' => Product::factory(),
            // Within the configured bounds by construction: a fixture above the cap would be a
            // shape BasketSession clamps away on every write.
            'quantity' => fake()->numberBetween(
                (int) config('koszykomat.basket.min_quantity'),
                (int) config('koszykomat.basket.max_quantity'),
            ),
        ];
    }
}
