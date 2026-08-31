<?php

namespace Tests\Feature\Database;

use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A factory row must be a shape production can actually hold.
 *
 * The schema cannot express "leaflet and listing belong to the same chain" — no composite foreign
 * key covers it today — so a factory that builds each child independently silently produces a Lidl
 * price inside a Biedronka leaflet, and every test built on it reasons about incoherent data.
 *
 * Covering only the default create() path is not enough, which is how the rule was broken a second
 * time: deriving the listing from the leaflet protects the default, but a relationship override
 * replaces that derivation and leaves the leaflet in a chain of its own. Every construction path a
 * caller can take is therefore covered here, and the last test proves the guard has teeth — without
 * it, a green coherence suite would show only that nobody tried.
 */
class PriceEntryFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function assertChainsAgree(PriceEntry $entry, string $path): void
    {
        $this->assertSame(
            $entry->leaflet->network_id,
            $entry->networkProduct->network_id,
            "A price entry built via {$path} must sit in a leaflet belonging to the same chain as its listing."
        );
    }

    public function test_the_default_entry_keeps_the_leaflet_and_listing_in_one_network(): void
    {
        $this->assertChainsAgree(PriceEntry::factory()->create(), 'create()');
    }

    /**
     * Every promo state, including conditional_unit_price — the fifth mechanic, added for Lidl's
     * dominant offer shape and previously missing from this list.
     *
     * @return array<string, array{string}>
     */
    public static function promoStates(): array
    {
        return [
            'simple' => ['simple'],
            'one_plus_one' => ['onePlusOne'],
            'second_for_fixed' => ['secondForFixed'],
            'loyalty_card' => ['loyaltyCard'],
            'conditional_unit_price' => ['conditionalUnitPrice'],
        ];
    }

    #[DataProvider('promoStates')]
    public function test_every_promo_state_keeps_one_network(string $state): void
    {
        $this->assertChainsAgree(PriceEntry::factory()->{$state}()->create(), "{$state}()");
    }

    /**
     * The parameterised thresholds real leaflets print must be as coherent as the defaults —
     * passing a threshold must not change how the parents are resolved.
     */
    public function test_parameterised_thresholds_keep_one_network(): void
    {
        $this->assertChainsAgree(PriceEntry::factory()->onePlusOne(3)->create(), 'onePlusOne(3)');
        $this->assertChainsAgree(PriceEntry::factory()->secondForFixed('0.01', 3)->create(), "secondForFixed('0.01', 3)");
        $this->assertChainsAgree(PriceEntry::factory()->conditionalUnitPrice('1.99', 6)->create(), "conditionalUnitPrice('1.99', 6)");
    }

    /**
     * The supported way to attach a price to a listing that already exists.
     */
    public function test_for_listing_keeps_one_network_and_pins_the_listing(): void
    {
        $listing = NetworkProduct::factory()->create();

        $entry = PriceEntry::factory()->forListing($listing)->create();

        $this->assertChainsAgree($entry, 'forListing()');
        $this->assertSame($listing->id, $entry->network_product_id, 'forListing() must pin the listing it was given.');
        $this->assertSame($listing->network_id, $entry->leaflet->network_id);
    }

    /**
     * The guard itself. Pinning only the listing leaves `leaflet_id` on its default factory, which
     * builds a fresh Leaflet in a fresh Network — the exact shape lessons.md forbids, and the one
     * that was live in this suite until it was fixed.
     *
     * Without this assertion the tests above would pass on a factory whose guard had been deleted.
     */
    public function test_pinning_only_the_listing_is_refused(): void
    {
        $listing = NetworkProduct::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cross-chain/');

        PriceEntry::factory()->for($listing, 'networkProduct')->create();
    }
}
