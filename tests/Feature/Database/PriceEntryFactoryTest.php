<?php

namespace Tests\Feature\Database;

use App\Models\PriceEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A default factory row must be a shape production can actually hold.
 *
 * The schema cannot express "leaflet and listing belong to the same chain" — no composite foreign
 * key covers it — so a factory that builds each child independently silently produces a Lidl price
 * inside a Biedronka leaflet, and every test built on it reasons about incoherent data.
 */
class PriceEntryFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_entry_keeps_the_leaflet_and_listing_in_one_network(): void
    {
        $entry = PriceEntry::factory()->create();

        $this->assertSame(
            $entry->leaflet->network_id,
            $entry->networkProduct->network_id,
            'A price entry must sit in a leaflet belonging to the same chain as its listing.'
        );
    }

    public function test_every_promo_state_keeps_one_network(): void
    {
        $states = [
            PriceEntry::factory()->simple(),
            PriceEntry::factory()->onePlusOne(),
            PriceEntry::factory()->secondForFixed(),
            PriceEntry::factory()->loyaltyCard(),
        ];

        foreach ($states as $factory) {
            $entry = $factory->create();

            $this->assertSame($entry->leaflet->network_id, $entry->networkProduct->network_id);
        }
    }
}
