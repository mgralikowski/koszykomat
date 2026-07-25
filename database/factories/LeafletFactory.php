<?php

namespace Database\Factories;

use App\Models\Leaflet;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leaflet>
 */
class LeafletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The window is anchored to today so factory-built leaflets are always current — a test
     * that wants an expired one should say so explicitly rather than depend on the calendar.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => Network::factory(),
            'name' => 'Gazetka testowa',
            'valid_from' => today()->startOfWeek(),
            'valid_to' => today()->endOfWeek(),
            'source_type' => 'manual',
            'source_reference' => null,
        ];
    }
}
