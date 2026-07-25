<?php

namespace Database\Factories;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceEntry>
 *
 * Each promo state sets exactly the parameters its mechanic requires and nulls the rest, so a
 * test cannot accidentally build a row that violates the PromoType parameter contract.
 */
class PriceEntryFactory extends Factory
{
    /**
     * Define the model's default state: a plain shelf price, no promo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leaflet_id' => Leaflet::factory(),
            'network_product_id' => NetworkProduct::factory(),
            'regular_price' => '9.99',
            'promo_type' => PromoType::None,
            'promo_price' => null,
            'required_quantity' => null,
            'second_item_price' => null,
        ];
    }

    /**
     * A simple discounted unit price.
     */
    public function simple(string $promoPrice = '7.99'): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::Simple,
            'promo_price' => $promoPrice,
            'required_quantity' => null,
            'second_item_price' => null,
        ]);
    }

    /**
     * Buy one, get the second free — the second item costs 0.00.
     */
    public function onePlusOne(): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::OnePlusOne,
            'promo_price' => null,
            'required_quantity' => 2,
            'second_item_price' => '0.00',
        ]);
    }

    /**
     * Second item for a fixed amount — a złoty or a grosz.
     */
    public function secondForFixed(string $secondItemPrice = '1.00'): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::SecondForFixed,
            'promo_price' => null,
            'required_quantity' => 2,
            'second_item_price' => $secondItemPrice,
        ]);
    }

    /**
     * Price available only with the chain's loyalty card.
     */
    public function loyaltyCard(string $promoPrice = '6.49'): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::LoyaltyCard,
            'promo_price' => $promoPrice,
            'required_quantity' => null,
            'second_item_price' => null,
        ]);
    }
}
