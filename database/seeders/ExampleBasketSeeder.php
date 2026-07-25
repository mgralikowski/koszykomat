<?php

namespace Database\Seeders;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Hand-seeds the dataset the guest homepage comparison runs on (FR-001) until real leaflet
 * ingestion exists.
 *
 * The products come from `config('koszykomat.example_basket')`, so the fixture the comparison
 * reads and the data seeded here cannot drift apart. Everything else — the chains' own product
 * names, brands, size labels and prices — lives in the catalogue below.
 *
 * The dataset is deliberately shaped to exercise the product rather than merely populate tables:
 *
 * - all five PromoType cases appear, so the rule engine has every mechanic to price;
 * - one listing carries both a regular and a loyalty-card price in the same leaflet, the case
 *   where the card can flip the verdict;
 * - two pairs differ in brand and one in size label, so the report has real FR-008 differences
 *   to show rather than a uniform set where the "brand differs" note never appears;
 * - each chain wins two of the four lines, so the verdict has to be computed rather than being
 *   trivially true (see the per-line notes in the catalogue).
 *
 * Idempotent: every write is an updateOrCreate on the row's natural key, so re-running against a
 * non-fresh database updates in place instead of duplicating or hitting a unique index.
 */
class ExampleBasketSeeder extends Seeder
{
    /**
     * Chains in the MVP, keyed by slug.
     */
    private const NETWORKS = [
        'lidl' => 'Lidl',
        'biedronka' => 'Biedronka',
    ];

    /**
     * Identifies the leaflets this seeder owns, so re-runs update them rather than adding more.
     */
    private const LEAFLET_SOURCE_REFERENCE = 'seed:example-basket';

    public function run(): void
    {
        $networks = $this->seedNetworks();
        $leaflets = $this->seedLeaflets($networks);
        $catalogue = $this->catalogue();

        foreach (config('koszykomat.example_basket') as $item) {
            $slug = $item['product'];

            if (! isset($catalogue[$slug])) {
                throw new RuntimeException(
                    "Product [{$slug}] is in config('koszykomat.example_basket') but has no entry in the seeder catalogue."
                );
            }

            $product = Product::updateOrCreate(
                ['slug' => $slug],
                ['name' => $catalogue[$slug]['name']],
            );

            foreach ($catalogue[$slug]['listings'] as $networkSlug => $listing) {
                $networkProduct = NetworkProduct::updateOrCreate(
                    [
                        'network_id' => $networks[$networkSlug]->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'name' => $listing['name'],
                        'brand' => $listing['brand'],
                        'size_label' => $listing['size_label'],
                    ],
                );

                foreach ($listing['prices'] as $price) {
                    PriceEntry::updateOrCreate(
                        [
                            'leaflet_id' => $leaflets[$networkSlug]->id,
                            'network_product_id' => $networkProduct->id,
                            'promo_type' => $price['promo_type'],
                        ],
                        [
                            'regular_price' => $price['regular_price'],
                            'promo_price' => $price['promo_price'] ?? null,
                            'required_quantity' => $price['required_quantity'] ?? null,
                            'second_item_price' => $price['second_item_price'] ?? null,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @return array<string, Network>
     */
    private function seedNetworks(): array
    {
        $networks = [];

        foreach (self::NETWORKS as $slug => $name) {
            $networks[$slug] = Network::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        return $networks;
    }

    /**
     * One current leaflet per chain.
     *
     * The window is anchored to the current week rather than to literal dates — hardcoded dates
     * would silently expire and take the homepage comparison down with them.
     *
     * @param  array<string, Network>  $networks
     * @return array<string, Leaflet>
     */
    private function seedLeaflets(array $networks): array
    {
        $leaflets = [];

        foreach ($networks as $slug => $network) {
            $leaflets[$slug] = Leaflet::updateOrCreate(
                [
                    'network_id' => $network->id,
                    'source_reference' => self::LEAFLET_SOURCE_REFERENCE,
                ],
                [
                    'name' => 'Gazetka '.$network->name.' (przykładowa)',
                    'valid_from' => today()->startOfWeek(),
                    'valid_to' => today()->endOfWeek(),
                    'source_type' => 'manual',
                ],
            );
        }

        return $leaflets;
    }

    /**
     * The seeded catalogue, keyed by canonical product slug.
     *
     * Per-line outcome for the four-item example basket (quantities from the config):
     *
     * | line                    | qty | Lidl                            | Biedronka                        | winner    |
     * | ----------------------- | --- | ------------------------------- | -------------------------------- | --------- |
     * | mleko-32-1l             |  2  | 1+1 gratis        →  3.49       | promo 2.99/szt      →  5.98      | Lidl      |
     * | maslo-extra-200g        |  1  | regular           →  7.99       | z kartą 6.49        →  6.49      | Biedronka |
     * | kawa-ziarnista-1kg      |  1  | promo 32.99       → 32.99       | regular 44.99       → 44.99      | Lidl      |
     * | czekolada-mleczna-100g  |  4  | regular 4.49      → 17.96       | drugi za grosz      → 10.00      | Biedronka |
     *
     * Totals: Lidl 62.43 vs Biedronka 67.46 (with the loyalty card) — close, not tied, and split
     * two lines each, so the verdict is a real computation. Without the card Biedronka is 69.46,
     * which is the "card moves the number" case the report has to be honest about.
     *
     * @return array<string, array{name: string, listings: array<string, array{name: string, brand: ?string, size_label: ?string, prices: list<array<string, mixed>>}>}>
     */
    private function catalogue(): array
    {
        return [
            'mleko-32-1l' => [
                'name' => 'Mleko 3,2% 1 l',
                'listings' => [
                    // Brand differs: Pilos (private label) vs Łowicz — an FR-008 difference the
                    // report must surface so the user can judge the pairing.
                    'lidl' => [
                        'name' => 'Mleko świeże Pilos 3,2%',
                        'brand' => 'Pilos',
                        'size_label' => '1 l',
                        'prices' => [
                            [
                                'regular_price' => '3.49',
                                'promo_type' => PromoType::OnePlusOne,
                                'required_quantity' => 2,
                                'second_item_price' => '0.00',
                            ],
                        ],
                    ],
                    'biedronka' => [
                        'name' => 'Mleko świeże Łowicz 3,2%',
                        'brand' => 'Łowicz',
                        'size_label' => '1 l',
                        'prices' => [
                            [
                                'regular_price' => '3.29',
                                'promo_type' => PromoType::Simple,
                                'promo_price' => '2.99',
                            ],
                        ],
                    ],
                ],
            ],

            'maslo-extra-200g' => [
                'name' => 'Masło extra 200 g',
                'listings' => [
                    'lidl' => [
                        'name' => 'Masło extra Pilos',
                        'brand' => 'Pilos',
                        'size_label' => '200 g',
                        'prices' => [
                            [
                                'regular_price' => '7.99',
                                'promo_type' => PromoType::None,
                            ],
                        ],
                    ],
                    // Two entries on one listing in one leaflet: the shelf price and the card
                    // price. This is the mechanic that can flip the verdict depending on whether
                    // the shopper has the card, so both have to be readable side by side.
                    'biedronka' => [
                        'name' => 'Masło extra Mlekovita',
                        'brand' => 'Mlekovita',
                        'size_label' => '200 g',
                        'prices' => [
                            [
                                'regular_price' => '8.49',
                                'promo_type' => PromoType::None,
                            ],
                            [
                                'regular_price' => '8.49',
                                'promo_type' => PromoType::LoyaltyCard,
                                'promo_price' => '6.49',
                            ],
                        ],
                    ],
                ],
            ],

            'kawa-ziarnista-1kg' => [
                'name' => 'Kawa ziarnista 1 kg',
                'listings' => [
                    // Size label differs: 1 kg vs 900 g. Weight normalization is a PRD non-goal,
                    // so the report shows the difference and lets the user decide.
                    'lidl' => [
                        'name' => 'Kawa ziarnista Bellarom',
                        'brand' => 'Bellarom',
                        'size_label' => '1 kg',
                        'prices' => [
                            [
                                'regular_price' => '39.99',
                                'promo_type' => PromoType::Simple,
                                'promo_price' => '32.99',
                            ],
                        ],
                    ],
                    'biedronka' => [
                        'name' => 'Kawa ziarnista Lavazza',
                        'brand' => 'Lavazza',
                        'size_label' => '900 g',
                        'prices' => [
                            [
                                'regular_price' => '44.99',
                                'promo_type' => PromoType::None,
                            ],
                        ],
                    ],
                ],
            ],

            'czekolada-mleczna-100g' => [
                'name' => 'Czekolada mleczna 100 g',
                'listings' => [
                    'lidl' => [
                        'name' => 'Czekolada mleczna J.D. Gross',
                        'brand' => 'J.D. Gross',
                        'size_label' => '100 g',
                        'prices' => [
                            [
                                'regular_price' => '4.49',
                                'promo_type' => PromoType::None,
                            ],
                        ],
                    ],
                    // Quantity 4 in the example basket means this mechanic applies twice — and at
                    // an odd quantity it would force the overbuy the report has to price honestly.
                    'biedronka' => [
                        'name' => 'Czekolada mleczna Wedel',
                        'brand' => 'Wedel',
                        'size_label' => '100 g',
                        'prices' => [
                            [
                                'regular_price' => '4.99',
                                'promo_type' => PromoType::SecondForFixed,
                                'required_quantity' => 2,
                                'second_item_price' => '0.01',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
