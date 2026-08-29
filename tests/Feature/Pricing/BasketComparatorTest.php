<?php

namespace Tests\Feature\Pricing;

use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use App\Pricing\BasketComparator;
use App\Pricing\ComparisonReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four mandatory promo-mechanic tests required by CLAUDE.md: each of the four mechanics gets
 * a test asserting a computed basket total.
 *
 * Fixtures are built here rather than taken from ExampleBasketSeeder so every number in an
 * assertion is derivable from the lines above it, and so a future edit to the seed cannot quietly
 * change what these tests mean.
 */
class BasketComparatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prices_a_simple_promo_price(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->simple('2.99')->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '3.29',
        ]);

        // 2 × 2,99 zł
        $this->assertBasketTotal('5.98', quantity: 2);
    }

    public function test_it_prices_one_plus_one_free(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->onePlusOne()->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '3.49',
        ]);

        // 4 items = 2 pairs, each pair costs one regular price: 2 × 3,49 zł
        $this->assertBasketTotal('6.98', quantity: 4);
    }

    public function test_it_prices_a_second_item_for_one_grosz(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->secondForFixed('0.01')->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '4.99',
        ]);

        // 4 items = 2 pairs, each pair costs 4,99 + 0,01 zł
        $this->assertBasketTotal('10.00', quantity: 4);
    }

    public function test_it_prices_a_loyalty_card_price_only_for_card_holders(): void
    {
        $listing = $this->listing();
        $leaflet = $this->leafletFor($listing);

        PriceEntry::factory()->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '8.49',
        ]);

        PriceEntry::factory()->loyaltyCard('6.49')->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '8.49',
        ]);

        $report = $this->compare(quantity: 1);

        $this->assertSame('8.49', $report->withoutCard->resultFor('lidl')->total->toDecimalString());
        $this->assertSame('6.49', $report->withCard->resultFor('lidl')->total->toDecimalString());
    }

    private function assertBasketTotal(string $expected, int $quantity): void
    {
        $report = $this->compare($quantity);

        $this->assertSame(
            $expected,
            $report->withoutCard->resultFor('lidl')->total->toDecimalString(),
        );
    }

    private function compare(int $quantity): ComparisonReport
    {
        return app(BasketComparator::class)->compare([
            ['product' => 'produkt', 'quantity' => $quantity],
        ]);
    }

    private function listing(): NetworkProduct
    {
        $network = Network::factory()->create(['slug' => 'lidl', 'name' => 'Lidl']);
        $product = Product::factory()->create(['slug' => 'produkt']);

        return NetworkProduct::factory()->create([
            'network_id' => $network->id,
            'product_id' => $product->id,
        ]);
    }

    private function leafletFor(NetworkProduct $listing): Leaflet
    {
        return Leaflet::factory()->create(['network_id' => $listing->network_id]);
    }
}
