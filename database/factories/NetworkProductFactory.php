<?php

namespace Database\Factories;

use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkProduct>
 */
class NetworkProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => Network::factory(),
            'product_id' => Product::factory(),
            'name' => 'Produkt testowy sieci',
            'brand' => 'Marka testowa',
            'size_label' => '1 l',
        ];
    }
}
