<?php

namespace Tests\Feature\Pricing;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use App\Pricing\BasketComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other half of the guardrail: a row the gate flagged must be invisible to the verdict.
 *
 * Flagging a row is worthless if the comparison still reads it, so this asserts the exclusion at
 * the level that matters — the computed verdict, not the scope in isolation.
 */
class BasketComparatorTrustTest extends TestCase
{
    use RefreshDatabase;

    private function comparator(): BasketComparator
    {
        return app(BasketComparator::class);
    }

    /**
     * Two chains, one canonical product, a priced entry in each — the caller decides whether the
     * second chain's entry is trusted.
     *
     * @return array{0: string, 1: PriceEntry}
     */
    private function seedOneProductInBothChains(bool $secondChainNeedsReview): array
    {
        $product = Product::factory()->create(['slug' => 'mleko-32-1l']);

        $entries = [];

        foreach ([['lidl', '3.49'], ['biedronka', '3.99']] as [$slug, $price]) {
            $network = Network::factory()->create(['slug' => $slug]);
            $leaflet = Leaflet::factory()->for($network)->create();
            $listing = NetworkProduct::factory()->for($network)->for($product)->create();

            $entries[$slug] = PriceEntry::factory()
                ->for($leaflet)
                ->for($listing, 'networkProduct')
                ->create([
                    'regular_price' => $price,
                    'promo_type' => PromoType::None,
                    'needs_review' => $slug === 'biedronka' ? $secondChainNeedsReview : false,
                ]);
        }

        return ['mleko-32-1l', $entries['biedronka']];
    }

    public function test_a_flagged_entry_yields_no_data_rather_than_a_verdict(): void
    {
        [$slug] = $this->seedOneProductInBothChains(secondChainNeedsReview: true);

        $report = $this->comparator()->compare([['product' => $slug, 'quantity' => 1]]);

        $verdict = $report->withoutCard->verdict;

        $this->assertTrue($verdict->isNoData(), 'A flagged price must suppress the verdict entirely.');
        $this->assertFalse($verdict->hasWinner());
        $this->assertContains($slug, $verdict->missingProducts);
    }

    public function test_the_same_data_produces_a_verdict_once_the_entry_is_trusted(): void
    {
        [$slug] = $this->seedOneProductInBothChains(secondChainNeedsReview: false);

        $report = $this->comparator()->compare([['product' => $slug, 'quantity' => 1]]);

        $verdict = $report->withoutCard->verdict;

        $this->assertTrue($verdict->hasWinner(), 'Trusted rows on both sides must produce a winner.');
        $this->assertSame('lidl', $verdict->winner->slug);
    }

    public function test_flagging_the_cheaper_entry_never_hands_the_win_to_the_other_chain(): void
    {
        // The failure this guards against: dropping the flagged row and comparing what is left,
        // which would name Biedronka the winner on a basket Lidl simply could not be priced for.
        [$slug] = $this->seedOneProductInBothChains(secondChainNeedsReview: false);

        PriceEntry::query()
            ->whereHas('networkProduct.network', fn ($query) => $query->where('slug', 'lidl'))
            ->update(['needs_review' => true]);

        $report = $this->comparator()->compare([['product' => $slug, 'quantity' => 1]]);

        $this->assertTrue($report->withoutCard->verdict->isNoData());
        $this->assertNull($report->withoutCard->verdict->winner);
    }
}
