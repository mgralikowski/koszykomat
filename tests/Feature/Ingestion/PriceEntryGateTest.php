<?php

namespace Tests\Feature\Ingestion;

use App\Enums\PromoType;
use App\Ingestion\Validation\PriceEntryCandidate;
use App\Ingestion\Validation\PriceEntryGate;
use App\Models\Leaflet;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gate is the data-side half of the PRD guardrail: a row it flags can never reach a verdict.
 *
 * These tests are the only thing standing between a mis-parsed leaflet and a confidently wrong
 * price, so each rejection case mirrors a shape a parser can actually produce.
 */
class PriceEntryGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): PriceEntryGate
    {
        return new PriceEntryGate;
    }

    public function test_it_trusts_a_well_formed_row_of_each_mechanic(): void
    {
        $cases = [
            new PriceEntryCandidate(PromoType::None, '5.99'),
            new PriceEntryCandidate(PromoType::Simple, '5.99', promoPrice: '4.59'),
            new PriceEntryCandidate(PromoType::LoyaltyCard, '5.99', promoPrice: '4.99'),
            new PriceEntryCandidate(PromoType::OnePlusOne, '5.99', requiredQuantity: 2, secondItemPrice: '0.00'),
            new PriceEntryCandidate(PromoType::SecondForFixed, '5.99', requiredQuantity: 2, secondItemPrice: '0.01'),
        ];

        foreach ($cases as $candidate) {
            $verdict = $this->gate()->inspect($candidate);

            $this->assertFalse(
                $verdict->needsReview,
                "{$candidate->promoType->value} should be trusted, got: {$verdict->summary()}"
            );
        }
    }

    public function test_it_flags_a_conditional_mechanic_that_requires_only_one_item(): void
    {
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::OnePlusOne, '5.99', requiredQuantity: 1, secondItemPrice: '0.00')
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('required_quantity', $verdict->summary());
    }

    public function test_it_flags_a_promo_price_that_is_not_a_discount(): void
    {
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::Simple, '4.59', promoPrice: '5.99')
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('promo_price', $verdict->summary());
    }

    public function test_it_flags_one_plus_one_whose_second_item_is_not_free(): void
    {
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::OnePlusOne, '5.99', requiredQuantity: 2, secondItemPrice: '2.50')
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('one_plus_one', $verdict->summary());
    }

    public function test_it_flags_a_forbidden_parameter(): void
    {
        // A hallucinated shape: the mechanic says "1+1" but the row also carries a unit promo price.
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(
                PromoType::OnePlusOne,
                '5.99',
                promoPrice: '3.00',
                requiredQuantity: 2,
                secondItemPrice: '0.00',
            )
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('promo_price must be null', $verdict->summary());
    }

    public function test_it_flags_a_missing_required_parameter(): void
    {
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::Simple, '5.99')
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('promo_price is required', $verdict->summary());
    }

    public function test_it_flags_a_non_numeric_regular_price_instead_of_throwing(): void
    {
        // What a vision model actually returns for a Polish leaflet. Money::fromDecimalString()
        // would throw on this; the gate must flag it and stay quiet.
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::None, '19,99 zł')
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('regular_price', $verdict->summary());
    }

    public function test_it_flags_an_implausible_swing_against_the_last_known_price(): void
    {
        $listing = NetworkProduct::factory()->create();
        PriceEntry::factory()->forListing($listing)->create([
            'regular_price' => '17.99',
            'promo_type' => PromoType::None,
            'needs_review' => false,
        ]);

        // A misread decimal point: 17,99 becomes 1799.
        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::None, '1799.00', networkProductId: $listing->id)
        );

        $this->assertTrue($verdict->needsReview);
        $this->assertStringContainsString('moved', $verdict->summary());
    }

    public function test_it_allows_a_real_discount_within_the_plausibility_band(): void
    {
        $listing = NetworkProduct::factory()->create();
        PriceEntry::factory()->forListing($listing)->create([
            'regular_price' => '17.99',
            'promo_type' => PromoType::None,
            'needs_review' => false,
        ]);

        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::None, '12.99', networkProductId: $listing->id)
        );

        $this->assertFalse($verdict->needsReview, $verdict->summary());
    }

    public function test_a_listing_with_no_history_is_not_flagged_on_plausibility(): void
    {
        $listing = NetworkProduct::factory()->create();
        Leaflet::factory()->create();

        $verdict = $this->gate()->inspect(
            new PriceEntryCandidate(PromoType::None, '999.00', networkProductId: $listing->id)
        );

        $this->assertFalse($verdict->needsReview, $verdict->summary());
    }
}
