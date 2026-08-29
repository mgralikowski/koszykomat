<?php

namespace Tests\Feature\Pricing;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use App\Pricing\BasketComparator;
use App\Pricing\ComparisonReport;
use App\Pricing\VerdictType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The paths the seed and the four mandatory tests do not reach — the ones where a wrong answer
 * would be silent rather than obvious.
 *
 * Both seeded conditional lines use even quantities, so odd-quantity behaviour is proven here or
 * nowhere.
 */
class BasketComparatorEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_odd_quantity_under_one_plus_one_pays_for_the_leftover_item(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->onePlusOne()->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '3.49',
        ]);

        // 3 items = one pair (3,49) + one leftover at the regular price (3,49).
        // Not 3,49 (that would give the third away) and not 6,98-for-4 (that would overbuy).
        $report = $this->compare(quantity: 3);

        $this->assertSame('6.98', $this->total($report));
    }

    public function test_an_odd_quantity_under_second_for_fixed_pays_for_the_leftover_item(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->secondForFixed('0.01')->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '4.99',
        ]);

        // 3 items = (4,99 + 0,01) + 4,99. Buying 4 would cost 10,00 — we never round up.
        $report = $this->compare(quantity: 3);

        $this->assertSame('9.99', $this->total($report));
    }

    public function test_a_quantity_below_the_required_one_pays_the_regular_price_and_is_flagged(): void
    {
        $listing = $this->listing();

        PriceEntry::factory()->onePlusOne()->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '3.49',
        ]);

        $report = $this->compare(quantity: 1);
        $line = $report->withoutCard->resultFor('lidl')->lineFor('produkt');

        $this->assertSame('3.49', $line->total->toDecimalString());
        $this->assertTrue(
            $line->promoRequiredMoreItems,
            'A 1+1 offer that did not fire must be flagged, so the report can say the promo needed more items.'
        );
    }

    public function test_the_cheapest_offer_wins_when_several_are_valid(): void
    {
        $listing = $this->listing();
        $leaflet = $this->leafletFor($listing);

        PriceEntry::factory()->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '10.00',
        ]);

        PriceEntry::factory()->simple('8.00')->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '10.00',
        ]);

        $line = $this->compare(quantity: 1)->withoutCard->resultFor('lidl')->lineFor('produkt');

        $this->assertSame('8.00', $line->total->toDecimalString());
        $this->assertSame(PromoType::Simple, $line->appliedPromo);
    }

    public function test_the_card_scenario_can_change_the_outcome(): void
    {
        $lidl = $this->network('lidl');
        $biedronka = $this->network('biedronka');
        $product = Product::factory()->create(['slug' => 'maslo']);

        $lidlListing = $this->listingFor($lidl, $product);
        PriceEntry::factory()->create([
            'leaflet_id' => $this->leafletFor($lidlListing)->id,
            'network_product_id' => $lidlListing->id,
            'regular_price' => '7.99',
        ]);

        $biedronkaListing = $this->listingFor($biedronka, $product);
        $biedronkaLeaflet = $this->leafletFor($biedronkaListing);
        PriceEntry::factory()->create([
            'leaflet_id' => $biedronkaLeaflet->id,
            'network_product_id' => $biedronkaListing->id,
            'regular_price' => '8.49',
        ]);
        PriceEntry::factory()->loyaltyCard('6.49')->create([
            'leaflet_id' => $biedronkaLeaflet->id,
            'network_product_id' => $biedronkaListing->id,
            'regular_price' => '8.49',
        ]);

        $report = app(BasketComparator::class)->compare([['product' => 'maslo', 'quantity' => 1]]);

        $this->assertSame('lidl', $report->withoutCard->verdict->winner->slug);
        $this->assertSame('biedronka', $report->withCard->verdict->winner->slug);
        $this->assertTrue($report->cardChangesOutcome());
    }

    public function test_expired_data_yields_no_data_but_the_same_fixture_prices_inside_its_window(): void
    {
        $listing = $this->listing();

        $leaflet = Leaflet::factory()->create([
            'network_id' => $listing->network_id,
            'valid_from' => today()->subDays(20),
            'valid_to' => today()->subDays(10),
        ]);

        PriceEntry::factory()->create([
            'leaflet_id' => $leaflet->id,
            'network_product_id' => $listing->id,
            'regular_price' => '5.00',
        ]);

        $expired = $this->compare(quantity: 1);

        $this->assertSame(VerdictType::NoData, $expired->withoutCard->verdict->type);
        $this->assertNull($expired->withoutCard->resultFor('lidl')->total);
        $this->assertSame(['produkt'], $expired->withoutCard->verdict->missingProducts);

        $inWindow = app(BasketComparator::class)->compare(
            [['product' => 'produkt', 'quantity' => 1]],
            today()->subDays(15),
        );

        $this->assertSame(VerdictType::Winner, $inWindow->withoutCard->verdict->type);
        $this->assertSame('5.00', $inWindow->withoutCard->resultFor('lidl')->total->toDecimalString());
    }

    public function test_a_product_missing_from_one_chain_suppresses_the_verdict_but_keeps_the_priced_line(): void
    {
        $lidl = $this->network('lidl');
        $this->network('biedronka');
        $product = Product::factory()->create(['slug' => 'produkt']);

        $lidlListing = $this->listingFor($lidl, $product);
        PriceEntry::factory()->create([
            'leaflet_id' => $this->leafletFor($lidlListing)->id,
            'network_product_id' => $lidlListing->id,
            'regular_price' => '4.00',
        ]);

        $report = $this->compare(quantity: 1);

        $this->assertSame(VerdictType::NoData, $report->withoutCard->verdict->type);
        $this->assertNotNull(
            $report->withoutCard->resultFor('lidl')->lineFor('produkt'),
            'The line Lidl could price must still be shown — only the verdict is withheld.'
        );
        $this->assertSame(['produkt'], $report->withoutCard->resultFor('biedronka')->unpricedProducts);
    }

    public function test_a_product_absent_from_the_database_yields_no_data_rather_than_an_error(): void
    {
        $this->network('lidl');

        $report = app(BasketComparator::class)->compare([['product' => 'nie-ma-takiego', 'quantity' => 1]]);

        $this->assertSame(VerdictType::NoData, $report->withoutCard->verdict->type);
        $this->assertSame(['nie-ma-takiego'], $report->withoutCard->verdict->missingProducts);
    }

    public function test_a_malformed_conditional_entry_makes_the_line_unpriceable_instead_of_throwing(): void
    {
        $listing = $this->listing();

        // Reachable once ingestion writes real data: the promo-parameter contract is enforced in
        // application code, not in DDL, so required_quantity can be null on a conditional row.
        PriceEntry::factory()->onePlusOne()->create([
            'leaflet_id' => $this->leafletFor($listing)->id,
            'network_product_id' => $listing->id,
            'regular_price' => '3.49',
            'required_quantity' => null,
        ]);

        $report = $this->compare(quantity: 2);

        $this->assertSame(VerdictType::NoData, $report->withoutCard->verdict->type);
        $this->assertNull($report->withoutCard->resultFor('lidl')->total);
    }

    public function test_it_prices_a_multi_line_basket_without_an_n_plus_one(): void
    {
        $lidl = $this->network('lidl');
        $biedronka = $this->network('biedronka');

        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $product = Product::factory()->create(['slug' => $slug]);

            foreach ([$lidl, $biedronka] as $network) {
                $listing = $this->listingFor($network, $product);
                PriceEntry::factory()->create([
                    'leaflet_id' => $this->leafletFor($listing)->id,
                    'network_product_id' => $listing->id,
                    'regular_price' => '2.00',
                ]);
            }
        }

        $basket = array_map(fn (string $slug): array => ['product' => $slug, 'quantity' => 2], ['a', 'b', 'c', 'd']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(BasketComparator::class)->compare($basket);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Products + listings + networks (relation) + entries + leaflets + the networks lookup.
        // A per-line or per-chain query would push this into the dozens as the basket grows.
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "Comparing a 4-line basket took {$queries} queries — the eager load is not covering the pricing loop."
        );
    }

    private function compare(int $quantity): ComparisonReport
    {
        return app(BasketComparator::class)->compare([
            ['product' => 'produkt', 'quantity' => $quantity],
        ]);
    }

    private function total(ComparisonReport $report): string
    {
        return $report->withoutCard->resultFor('lidl')->total->toDecimalString();
    }

    private function network(string $slug): Network
    {
        return Network::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    }

    private function listing(): NetworkProduct
    {
        return $this->listingFor(
            $this->network('lidl'),
            Product::factory()->create(['slug' => 'produkt']),
        );
    }

    private function listingFor(Network $network, Product $product): NetworkProduct
    {
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
