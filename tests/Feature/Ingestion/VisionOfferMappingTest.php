<?php

namespace Tests\Feature\Ingestion;

use App\Enums\PromoType;
use App\Ingestion\Asset;
use App\Ingestion\Drivers\Biedronka\VisionParser;
use App\Ingestion\Flyer;
use App\Ingestion\Offer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The durable half of the gold set: a captured model response replayed through the offer mapping,
 * offline, with no network call and no page images.
 *
 * The spike that produced the response is a one-off; this is what outlives it. It pins the layer
 * between "what the model said" and "what reaches the database" — normalisation, mechanic parsing,
 * provenance — which is exactly where a well-formed response can still turn into a wrong row.
 */
class VisionOfferMappingTest extends TestCase
{
    private function asset(): Asset
    {
        $flyer = new Flyer(
            networkSlug: 'biedronka',
            externalId: 'codziennie-niskie-ceny-p-oferta-od-27-08',
            title: 'gold set',
            validFrom: Carbon::parse('2026-08-27'),
            validTo: Carbon::parse('2026-09-02'),
        );

        return new Asset($flyer, Asset::KIND_IMAGE, '/dev/null', pageNumber: 4);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return list<Offer>
     */
    private function map(?array $rows = null): array
    {
        if ($rows === null) {
            $captured = json_decode(
                (string) file_get_contents(base_path('tests/Fixtures/Ingestion/biedronka-gold-set/model-response.json')),
                true,
            );
            $rows = $captured['offers'];
        }

        return app(VisionParser::class)->toOffers($rows, $this->asset());
    }

    public function test_it_maps_a_captured_response_into_offers(): void
    {
        $offers = $this->map();

        $this->assertNotEmpty($offers);

        foreach ($offers as $offer) {
            $this->assertSame('biedronka', $offer->networkSlug);
            $this->assertSame(4, $offer->pageNumber);
            $this->assertStringStartsWith('biedronka.vision.', $offer->source);
            $this->assertNotSame('', $offer->rawName);
        }
    }

    public function test_it_normalises_money_the_model_returns_with_a_comma(): void
    {
        $offers = $this->map([[
            'name' => 'Mleko UHT 3,2%, 1 l',
            'regular_price' => '4,49 zł',
            'promo_type' => 'simple',
            'promo_price' => '1,99',
            'confidence' => 0.95,
        ]]);

        // Money::fromDecimalString() throws on "4,49 zł"; normalisation at this boundary is the
        // only reason PromoCalculator's never-throw contract survives contact with a model.
        $this->assertSame('4.49', $offers[0]->regularPrice);
        $this->assertSame('1.99', $offers[0]->promoPrice);
    }

    public function test_an_unreadable_amount_becomes_null_rather_than_an_exception(): void
    {
        $offers = $this->map([[
            'name' => 'Wszystkie parówki',
            'regular_price' => 'od 9,99 do 14,99',
            'promo_type' => 'none',
            'confidence' => 0.4,
        ]]);

        // A range names no single price. Null reaches the validation gate as a missing value and is
        // flagged; a guess would reach the verdict as a fact.
        $this->assertNull($offers[0]->regularPrice);
    }

    public function test_an_unknown_mechanic_degrades_to_none_instead_of_throwing(): void
    {
        $offers = $this->map([[
            'name' => 'Coś',
            'regular_price' => '5,00',
            'promo_type' => 'trzy_za_dwa',
            'confidence' => 0.9,
        ]]);

        $this->assertSame(PromoType::None, $offers[0]->promoType);
    }

    public function test_conditional_parameters_are_dropped_for_non_conditional_mechanics(): void
    {
        $offers = $this->map([[
            'name' => 'Cukier biały, 1 kg',
            'regular_price' => '3,49',
            'promo_type' => 'simple',
            'promo_price' => '1,99',
            // A model that fills in a required quantity for a flat discount is contradicting itself;
            // carrying it through would produce a row the parameter contract rejects for the wrong
            // reason, hiding what actually went wrong.
            'required_quantity' => '3',
            'second_item_price' => '0,01',
            'confidence' => 0.95,
        ]]);

        $this->assertNull($offers[0]->requiredQuantity);
        $this->assertNull($offers[0]->secondItemPrice);
    }

    public function test_a_corrupted_response_yields_no_offer_rather_than_a_wrong_one(): void
    {
        $offers = $this->map([
            ['regular_price' => '4,99', 'promo_type' => 'simple', 'confidence' => 0.9],
            ['name' => '   ', 'regular_price' => '4,99', 'promo_type' => 'simple', 'confidence' => 0.9],
        ]);

        // An offer with no name cannot be paired with any canonical product, so it could only ever
        // become an orphan row.
        $this->assertSame([], $offers);
    }

    public function test_confidence_is_clamped_and_recorded_to_two_decimals(): void
    {
        $offers = $this->map([
            ['name' => 'A', 'regular_price' => '1,00', 'promo_type' => 'none', 'confidence' => 1.7],
            ['name' => 'B', 'regular_price' => '1,00', 'promo_type' => 'none', 'confidence' => -0.2],
            ['name' => 'C', 'regular_price' => '1,00', 'promo_type' => 'none', 'confidence' => 'nie wiem'],
        ]);

        $this->assertSame('1.00', $offers[0]->confidence);
        $this->assertSame('0.00', $offers[1]->confidence);
        $this->assertNull($offers[2]->confidence);
    }
}
