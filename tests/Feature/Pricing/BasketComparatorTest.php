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
 * The five mandatory promo-mechanic tests required by CLAUDE.md: each of the five mechanics gets
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

    public function test_it_prices_a_conditional_unit_price(): void
    {
        $listing = $this->listing();
        $leaflet = $this->leafletFor($listing);

        // "cena za 1 opak. 4,00 przy zakupie 3 opak.", shelf price 6,00.
        PriceEntry::factory()->conditionalUnitPrice('4.00', 3)->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '6.00',
        ]);

        // Exactly the required quantity: every item in the complete group takes the conditional
        // price. The formula the other two conditional mechanics share would charge one item at the
        // shelf price and give 14,00 — the numbers are chosen so that mistake cannot pass.
        $this->assertBasketTotal('12.00', quantity: 3);

        // Below it the promotion does not exist at all, so the shelf price applies to everything.
        $this->assertBasketTotal('12.00', quantity: 2);

        // Not a whole multiple: one complete group discounted, the leftover item at the shelf price.
        $this->assertBasketTotal('18.00', quantity: 4);

        // Two complete groups.
        $this->assertBasketTotal('24.00', quantity: 6);
    }

    public function test_a_conditional_unit_price_below_its_required_quantity_is_reported_as_not_applied(): void
    {
        $listing = $this->listing();
        $leaflet = $this->leafletFor($listing);

        PriceEntry::factory()->conditionalUnitPrice('4.00', 3)->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '6.00',
        ]);

        $notApplied = $this->compare(quantity: 2)->withoutCard->resultFor('lidl')->lineFor('produkt');
        $applied = $this->compare(quantity: 3)->withoutCard->resultFor('lidl')->lineFor('produkt');

        // The report must say the headline price did not apply rather than showing it as achieved.
        $this->assertTrue($notApplied->promoRequiredMoreItems);
        $this->assertFalse($applied->promoRequiredMoreItems);
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
