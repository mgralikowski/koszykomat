<?php

namespace Tests\Feature\Database;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use Database\Seeders\ExampleBasketSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Guards the seeded dataset the guest comparison runs on.
 *
 * These assertions target the failure modes that would otherwise surface as a confusing or
 * dishonest verdict rather than as an obvious error: a product listed in only one chain, a promo
 * row whose parameters don't match its mechanic, or a leaflet that has quietly expired.
 */
class PricePromoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ExampleBasketSeeder::class);
    }

    public function test_it_seeds_both_networks(): void
    {
        $this->assertSame(2, Network::count());
        $this->assertNotNull(Network::where('slug', 'lidl')->first());
        $this->assertNotNull(Network::where('slug', 'biedronka')->first());
    }

    public function test_it_seeds_every_product_named_in_the_example_basket_config(): void
    {
        $basket = config('koszykomat.example_basket');

        $this->assertNotEmpty($basket, 'The example basket config must not be empty.');
        $this->assertGreaterThanOrEqual(3, count($basket), 'The PRD success criterion is a basket of at least 3 products.');

        foreach ($basket as $item) {
            $this->assertNotNull(
                Product::where('slug', $item['product'])->first(),
                "Product [{$item['product']}] from the example basket config was not seeded."
            );
        }
    }

    public function test_the_example_basket_exercises_a_conditional_mechanic(): void
    {
        $quantities = array_column(config('koszykomat.example_basket'), 'quantity');

        $this->assertGreaterThan(
            1,
            max($quantities),
            'At least one basket line needs quantity > 1, otherwise 1+1 and second-for-fixed never apply.'
        );
    }

    public function test_every_seeded_product_is_listed_in_both_networks(): void
    {
        $networkCount = Network::count();

        foreach (Product::all() as $product) {
            $listings = NetworkProduct::where('product_id', $product->id)->get();

            $this->assertCount(
                $networkCount,
                $listings,
                "Product [{$product->slug}] must be listed in every network for a comparison to be possible."
            );
            $this->assertSame(
                $networkCount,
                $listings->pluck('network_id')->unique()->count(),
                "Product [{$product->slug}] has duplicate listings within a single network."
            );
        }
    }

    public function test_every_listing_has_at_least_one_price_entry(): void
    {
        foreach (NetworkProduct::all() as $listing) {
            $this->assertGreaterThan(
                0,
                $listing->priceEntries()->count(),
                "Listing [{$listing->name}] has no price entry, so it cannot be compared."
            );
        }
    }

    public function test_all_promo_mechanics_are_represented(): void
    {
        $seeded = PriceEntry::all()
            ->map(fn (PriceEntry $entry): string => $entry->promo_type->value);

        foreach (PromoType::cases() as $case) {
            $this->assertTrue(
                $seeded->contains($case->value),
                "Promo mechanic [{$case->value}] is not present in the seed, so nothing exercises it."
            );
        }
    }

    public function test_every_price_entry_matches_its_promo_type_parameter_contract(): void
    {
        $entries = PriceEntry::all();

        $this->assertGreaterThan(0, $entries->count());

        foreach ($entries as $entry) {
            $type = $entry->promo_type;

            foreach ($type->requiredParameters() as $column) {
                $this->assertNotNull(
                    $entry->{$column},
                    "Entry #{$entry->id} is [{$type->value}] but [{$column}] is null."
                );
            }

            foreach ($type->forbiddenParameters() as $column) {
                $this->assertNull(
                    $entry->{$column},
                    "Entry #{$entry->id} is [{$type->value}] but [{$column}] is set."
                );
            }
        }
    }

    public function test_conditional_mechanics_require_at_least_two_items(): void
    {
        $conditional = PriceEntry::all()->filter(fn (PriceEntry $entry) => $entry->promo_type->isConditional());

        $this->assertGreaterThan(0, $conditional->count());

        foreach ($conditional as $entry) {
            $this->assertGreaterThanOrEqual(
                2,
                $entry->required_quantity,
                "Entry #{$entry->id} is a conditional mechanic but requires fewer than 2 items."
            );
        }
    }

    public function test_one_listing_carries_both_a_regular_and_a_loyalty_card_price(): void
    {
        $card = PriceEntry::where('promo_type', PromoType::LoyaltyCard)->firstOrFail();

        $this->assertNotNull(
            PriceEntry::where('leaflet_id', $card->leaflet_id)
                ->where('network_product_id', $card->network_product_id)
                ->where('promo_type', PromoType::None)
                ->first(),
            'A loyalty-card price needs its regular price alongside it in the same leaflet, or the card cannot be shown as a difference.'
        );
    }

    public function test_seeded_leaflets_are_valid_today(): void
    {
        $leaflets = Leaflet::all();

        $this->assertGreaterThan(0, $leaflets->count());

        foreach ($leaflets as $leaflet) {
            $this->assertTrue(
                $leaflet->valid_from->lessThanOrEqualTo(today()) && $leaflet->valid_to->greaterThanOrEqualTo(today()),
                "Seeded leaflet #{$leaflet->id} is not valid today ({$leaflet->valid_from->toDateString()} – {$leaflet->valid_to->toDateString()})."
            );
        }

        $this->assertSame(Leaflet::count(), Leaflet::validOn()->count());
        $this->assertSame(PriceEntry::count(), PriceEntry::validOn()->count());
    }

    public function test_validity_scopes_exclude_data_past_its_expiry(): void
    {
        $dayAfterLastExpiry = Carbon::parse(Leaflet::max('valid_to'))->addDay();

        $this->assertSame(0, Leaflet::validOn(today()->addYear())->count());
        $this->assertSame(0, PriceEntry::validOn(today()->addYear())->count());
        $this->assertSame(0, Leaflet::validOn($dayAfterLastExpiry)->count());
        $this->assertSame(0, PriceEntry::validOn($dayAfterLastExpiry)->count());
    }

    public function test_at_least_one_matched_pair_differs_in_brand_or_size_label(): void
    {
        $differing = Product::all()->filter(function (Product $product): bool {
            $listings = NetworkProduct::where('product_id', $product->id)->get();

            return $listings->pluck('brand')->unique()->count() > 1
                || $listings->pluck('size_label')->unique()->count() > 1;
        });

        $this->assertGreaterThan(
            0,
            $differing->count(),
            'No matched pair differs in brand or size label, so the report has no FR-008 difference to show.'
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = [
            'networks' => Network::count(),
            'products' => Product::count(),
            'network_products' => NetworkProduct::count(),
            'leaflets' => Leaflet::count(),
            'price_entries' => PriceEntry::count(),
        ];

        $this->seed(ExampleBasketSeeder::class);

        $this->assertSame($before, [
            'networks' => Network::count(),
            'products' => Product::count(),
            'network_products' => NetworkProduct::count(),
            'leaflets' => Leaflet::count(),
            'price_entries' => PriceEntry::count(),
        ]);
    }
}
