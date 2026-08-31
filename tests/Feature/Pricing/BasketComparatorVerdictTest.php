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
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Which chain the verdict names, when the mechanic is the only thing separating the two totals.
 *
 * This is the failure Risk #1 actually describes, and nothing in the suite could produce it before:
 * every mandatory mechanic test seeds a single chain (BasketComparatorTest.php:155), so it can
 * assert a total but never a verdict. A mechanic priced wrong there looks like a wrong number; here
 * it looks like the product telling a shopper to drive to the wrong shop.
 *
 * Every case is a deliberate near-tie. A wide margin would survive almost any mispricing and prove
 * nothing, so the totals are engineered to sit close enough that the mechanic decides the outcome.
 */
class BasketComparatorVerdictTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'mleko-32-1l';

    /**
     * Seed one canonical product priced in BOTH chains.
     *
     * The two-chain shape is the point: with a single chain, decide() returns a winner with a zero
     * margin (BasketComparator.php:242-244) — a verdict over nothing, and a shape production never
     * holds, since the MVP always carries Lidl and Biedronka.
     *
     * @param  array<string, mixed>  $lidl
     * @param  array<string, mixed>  $biedronka
     */
    private function seedBothChains(array $lidl, array $biedronka): void
    {
        $product = Product::factory()->create(['slug' => self::SLUG]);

        foreach (['lidl' => $lidl, 'biedronka' => $biedronka] as $networkSlug => $attributes) {
            $network = Network::factory()->create(['slug' => $networkSlug]);
            $listing = NetworkProduct::factory()->for($network)->for($product)->create();

            PriceEntry::factory()
                ->forListing($listing)
                ->create($attributes + ['needs_review' => false]);
        }

        $this->assertGreaterThanOrEqual(
            2,
            Network::count(),
            'A verdict must be asserted over at least two chains, or decide() names a winner with nothing to beat.'
        );
        $this->assertSame(
            Leaflet::count(),
            Leaflet::validOn()->count(),
            'Every seeded leaflet must be valid today, or the comparison abstains for the wrong reason.'
        );
    }

    private function compare(int $quantity): ComparisonReport
    {
        return app(BasketComparator::class)->compare([
            ['product' => self::SLUG, 'quantity' => $quantity],
        ]);
    }

    /**
     * Lidl's conditional unit price against a plain Biedronka promo, sized so the threshold decides
     * the winner: at six items Lidl's 1,99 unit price wins by six grosze; one item short, the promo
     * does not exist and Biedronka wins comfortably.
     *
     * Same fixture, opposite verdicts. That is the property worth protecting — a mechanic that
     * stopped respecting its threshold would flip one of these two without touching the other.
     */
    public function test_the_threshold_decides_which_chain_wins(): void
    {
        $this->seedBothChains(
            lidl: [
                'promo_type' => PromoType::ConditionalUnitPrice,
                'regular_price' => '3.30',
                'promo_price' => '1.99',
                'required_quantity' => 6,
            ],
            biedronka: [
                'promo_type' => PromoType::Simple,
                'regular_price' => '2.50',
                'promo_price' => '2.00',
            ],
        );

        // Six items: the group forms, so Lidl charges 6 x 1,99 against Biedronka's 6 x 2,00.
        $atThreshold = $this->compare(6)->withoutCard;

        $this->assertSame('11.94', $atThreshold->resultFor('lidl')->total->toDecimalString());
        $this->assertSame('12.00', $atThreshold->resultFor('biedronka')->total->toDecimalString());
        $this->assertSame(VerdictType::Winner, $atThreshold->verdict->type);
        $this->assertSame('lidl', $atThreshold->verdict->winner->slug);
        $this->assertSame('0.06', $atThreshold->verdict->margin->toDecimalString());

        // Five items: one short, so Lidl charges the shelf price and loses badly.
        $belowThreshold = $this->compare(5)->withoutCard;

        $this->assertSame('16.50', $belowThreshold->resultFor('lidl')->total->toDecimalString());
        $this->assertSame('10.00', $belowThreshold->resultFor('biedronka')->total->toDecimalString());
        $this->assertSame('biedronka', $belowThreshold->verdict->winner->slug);
        $this->assertSame('6.50', $belowThreshold->verdict->margin->toDecimalString());
    }

    /**
     * Equal totals must be reported as a tie, not as a winner with a zero margin — the shopper is
     * owed "remis", not an arbitrary recommendation.
     */
    public function test_equal_totals_are_a_tie_rather_than_an_arbitrary_winner(): void
    {
        $this->seedBothChains(
            lidl: ['promo_type' => PromoType::None, 'regular_price' => '5.00'],
            biedronka: ['promo_type' => PromoType::None, 'regular_price' => '5.00'],
        );

        $scenario = $this->compare(2)->withoutCard;

        $this->assertSame('10.00', $scenario->resultFor('lidl')->total->toDecimalString());
        $this->assertSame('10.00', $scenario->resultFor('biedronka')->total->toDecimalString());
        $this->assertSame(VerdictType::Tie, $scenario->verdict->type);
        $this->assertNull($scenario->verdict->winner, 'A tie must not name a winner.');
    }

    /**
     * The wrong verdict, from a real leaflet, end to end.
     *
     * Lidl prints "2+1 gratis" on a 4,49 chocolate (tests/Fixtures/Ingestion/lidl-tiles.txt:24-28),
     * which PdfTextParser stores as one_plus_one with a threshold of three. Buying three, a shopper
     * pays for two: 8,98. Biedronka's plain 2,80 promo comes to 8,40 — so Biedronka is cheaper and
     * the verdict must say so.
     *
     * The engine charges a single shelf price per group, making Lidl look like 4,49 and handing it
     * a win it has not earned. This is the guardrail failure the PRD calls out by name: the verdict
     * is confident, the data is fresh and trusted, and the answer is wrong.
     */
    #[Group('known-defect')]
    public function test_a_real_leaflet_offer_does_not_hand_the_verdict_to_the_wrong_chain(): void
    {
        $this->seedBothChains(
            lidl: [
                'promo_type' => PromoType::OnePlusOne,
                'regular_price' => '4.49',
                'required_quantity' => 3,
                'second_item_price' => '0.00',
            ],
            biedronka: [
                'promo_type' => PromoType::Simple,
                'regular_price' => '3.49',
                'promo_price' => '2.80',
            ],
        );

        $scenario = $this->compare(3)->withoutCard;

        // The winner is asserted first on purpose: it is the risk stated in its own terms, so the
        // failure message reads "expected biedronka, got lidl" rather than a difference of złoty.
        $this->assertSame('biedronka', $scenario->verdict->winner->slug, 'The till makes Biedronka cheaper, so the verdict must name Biedronka.');
        $this->assertSame('0.58', $scenario->verdict->margin->toDecimalString());
        $this->assertSame('8.98', $scenario->resultFor('lidl')->total->toDecimalString(), '2+1 gratis on three items means paying for two.');
        $this->assertSame('8.40', $scenario->resultFor('biedronka')->total->toDecimalString());
    }
}
