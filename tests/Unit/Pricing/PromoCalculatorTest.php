<?php

namespace Tests\Unit\Pricing;

use App\Enums\PromoType;
use App\Models\PriceEntry;
use App\Pricing\PromoCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * What a shopper actually pays at the till, per mechanic, per threshold.
 *
 * The oracle is the leaflet's own wording and the PRD — never the engine's arithmetic. Writing an
 * expectation as `regular + second × (N - 1)` would reproduce the formula under test and pass
 * against a bug; every expected value below is a literal derived by hand from a quoted leaflet
 * phrase, with the derivation named in the data-set key so a failure explains itself.
 *
 * Two rules this file exists to defend, both from PRD §Business Logic:
 *   - the shopper is charged for exactly the quantity asked for; nothing is added to unlock a
 *     promo, so a forced overbuy is *disclosed*, never folded into a total;
 *   - a conditional price applies to full groups only, with any remainder at the shelf price.
 *
 * No database is touched: PromoCalculator reads attributes off a PriceEntry and returns a
 * LineCost, so an unsaved model is the whole fixture.
 *
 * Methods tagged #[Group('known-defect')] assert the till truth at thresholds real leaflets print
 * and currently FAIL — see context/changes/testing-verdict-correctness/research.md §A.1-A.4.
 * They are excluded from `composer test` and run by `composer test:all`. They are expected to turn
 * green on their own once PromoCalculator::conditional() is fixed; nothing here needs editing then.
 */
class PromoCalculatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): PriceEntry
    {
        $entry = new PriceEntry;

        foreach ($attributes as $column => $value) {
            $entry->{$column} = $value;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertTillTotal(string $expected, array $attributes, int $quantity): void
    {
        $cost = (new PromoCalculator)->cost($this->entry($attributes), $quantity);

        $this->assertNotNull($cost, 'The row should be priceable.');
        $this->assertSame($expected, $cost->total->toDecimalString());
    }

    // ---------------------------------------------------------------- unconditional mechanics

    /**
     * @return array<string, array{int, string}>
     */
    public static function shelfPriceCases(): array
    {
        return [
            '1 item at 9,99' => [1, '9.99'],
            '3 items at 9,99' => [3, '29.97'],
        ];
    }

    #[DataProvider('shelfPriceCases')]
    public function test_no_promo_charges_the_shelf_price(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::None,
            'regular_price' => '9.99',
        ], $quantity);
    }

    /**
     * Lidl, "Taniej o 23%": 5,99 → 4,59 (context/research/vision.md:83).
     *
     * @return array<string, array{int, string}>
     */
    public static function simplePromoCases(): array
    {
        return [
            '1 item at the promo price' => [1, '4.59'],
            '2 items at the promo price' => [2, '9.18'],
            '5 items — no threshold, so every item is discounted' => [5, '22.95'],
        ];
    }

    #[DataProvider('simplePromoCases')]
    public function test_simple_promo_price(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::Simple,
            'regular_price' => '5.99',
            'promo_price' => '4.59',
        ], $quantity);
    }

    /**
     * A shopper can always decline a promo and pay the shelf price, so a promo price above the
     * regular one is never what they pay — and the mechanic reported must be the one charged.
     */
    public function test_a_simple_promo_price_above_the_regular_price_is_never_charged(): void
    {
        $entry = $this->entry([
            'promo_type' => PromoType::Simple,
            'regular_price' => '3.49',
            'promo_price' => '29.90',
        ]);

        $cost = (new PromoCalculator)->cost($entry, 2);

        $this->assertSame('6.98', $cost->total->toDecimalString());
        $this->assertSame(PromoType::None, $cost->appliedPromo, 'A clamped line must report the mechanic actually charged.');
    }

    /**
     * The card price is a straight per-unit discount, so it must scale with quantity. Until now it
     * was only ever asserted at a quantity of one.
     *
     * @return array<string, array{int, string}>
     */
    public static function loyaltyCardCases(): array
    {
        return [
            '1 item on the card price' => [1, '6.49'],
            '3 items — the card price is per unit' => [3, '19.47'],
        ];
    }

    #[DataProvider('loyaltyCardCases')]
    public function test_loyalty_card_price(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::LoyaltyCard,
            'regular_price' => '8.49',
            'promo_price' => '6.49',
        ], $quantity);
    }

    public function test_a_loyalty_card_price_above_the_regular_price_is_never_charged(): void
    {
        $entry = $this->entry([
            'promo_type' => PromoType::LoyaltyCard,
            'regular_price' => '8.49',
            'promo_price' => '9.99',
        ]);

        $cost = (new PromoCalculator)->cost($entry, 2);

        $this->assertSame('16.98', $cost->total->toDecimalString());
        $this->assertSame(PromoType::None, $cost->appliedPromo);
    }

    // ---------------------------------------------------------------- 1+1 gratis

    /**
     * Classic "1+1 gratis" — a group of two in which one item is free. Regular 3,49 as seeded.
     *
     * @return array<string, array{int, string}>
     */
    public static function onePlusOneAtTwoCases(): array
    {
        return [
            'N=2, qty 1 — below the group, shelf price' => [1, '3.49'],
            'N=2, qty 2 — one group: pay for 1 of 2' => [2, '3.49'],
            'N=2, qty 3 — one group plus a leftover at shelf price' => [3, '6.98'],
            'N=2, qty 4 — two groups: pay for 2 of 4' => [4, '6.98'],
        ];
    }

    #[DataProvider('onePlusOneAtTwoCases')]
    public function test_one_plus_one_at_the_seeded_threshold(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::OnePlusOne,
            'regular_price' => '3.49',
            'required_quantity' => 2,
            'second_item_price' => '0.00',
        ], $quantity);
    }

    /**
     * Lidl, verbatim: "2+1 gratis" / "Cena poza promocją: 4,49/opak."
     * (tests/Fixtures/Ingestion/lidl-tiles.txt:24-28).
     *
     * PdfTextParser reads "2+1" as one group of THREE (`(int) $m[1] + (int) $m[2]`, :360-362) and
     * the mechanic as one_plus_one. At the till a group of three costs TWO shelf prices — the
     * shopper pays for 2 and takes 1 free. The engine charges one shelf price per group, so it
     * undercharges by a full 4,49 per group.
     *
     * @return array<string, array{int, string}>
     */
    public static function onePlusOneAtThreeCases(): array
    {
        return [
            'N=3, qty 1 — below the group, shelf price' => [1, '4.49'],
            'N=3, qty 2 — still below the group' => [2, '8.98'],
            'N=3, qty 3 — one group: pay for 2 of 3' => [3, '8.98'],
            'N=3, qty 4 — one group plus a leftover at shelf price' => [4, '13.47'],
            'N=3, qty 6 — two groups: pay for 4 of 6' => [6, '17.96'],
        ];
    }

    #[Group('known-defect')]
    #[DataProvider('onePlusOneAtThreeCases')]
    public function test_one_plus_one_at_the_real_leaflet_threshold(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::OnePlusOne,
            'regular_price' => '4.49',
            'required_quantity' => 3,
            'second_item_price' => '0.00',
        ], $quantity);
    }

    // ---------------------------------------------------------------- second for a fixed amount

    /**
     * "Drugi za grosz" — a group of two, the second item at 0,01. Regular 4,99 as seeded.
     *
     * @return array<string, array{int, string}>
     */
    public static function secondForGroszAtTwoCases(): array
    {
        return [
            'N=2, qty 1 — below the group, shelf price' => [1, '4.99'],
            'N=2, qty 2 — one group: 4,99 + 0,01' => [2, '5.00'],
            'N=2, qty 3 — one group plus a leftover at shelf price' => [3, '9.99'],
            'N=2, qty 4 — two groups' => [4, '10.00'],
        ];
    }

    #[DataProvider('secondForGroszAtTwoCases')]
    public function test_second_for_a_grosz_at_the_seeded_threshold(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::SecondForFixed,
            'regular_price' => '4.99',
            'required_quantity' => 2,
            'second_item_price' => '0.01',
        ], $quantity);
    }

    /**
     * "Drugi za złotówkę" — the same mechanic with a 1,00 second item, which PdfTextParser emits
     * for that phrase. The złotówka variant was never asserted; only the grosz one was.
     *
     * @return array<string, array{int, string}>
     */
    public static function secondForZlotowkaAtTwoCases(): array
    {
        return [
            'N=2, qty 1 — below the group, shelf price' => [1, '10.99'],
            'N=2, qty 2 — one group: 10,99 + 1,00' => [2, '11.99'],
            'N=2, qty 3 — one group plus a leftover at shelf price' => [3, '22.98'],
            'N=2, qty 4 — two groups' => [4, '23.98'],
        ];
    }

    #[DataProvider('secondForZlotowkaAtTwoCases')]
    public function test_second_for_a_zloty_at_the_seeded_threshold(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::SecondForFixed,
            'regular_price' => '10.99',
            'required_quantity' => 2,
            'second_item_price' => '1.00',
        ], $quantity);
    }

    /**
     * Lidl, verbatim: "Trzeci, najtańszy za grosz." / "Cena poza promocją: 89,99/opak."
     * (tests/Fixtures/Ingestion/lidl-tiles.txt:30-34).
     *
     * PdfTextParser reads "trzeci" as a threshold of THREE (:367) and "za grosz" as
     * second_for_fixed at 0,01. At the till a group of three costs two shelf prices plus one
     * grosz — only the third item is discounted. The engine discounts every item after the first,
     * so it undercharges by almost a full 89,99 per group.
     *
     * @return array<string, array{int, string}>
     */
    public static function secondForGroszAtThreeCases(): array
    {
        return [
            'N=3, qty 1 — below the group, shelf price' => [1, '89.99'],
            'N=3, qty 2 — still below the group' => [2, '179.98'],
            'N=3, qty 3 — one group: 2 x 89,99 + 0,01' => [3, '179.99'],
            'N=3, qty 4 — one group plus a leftover at shelf price' => [4, '269.98'],
            'N=3, qty 6 — two groups' => [6, '359.98'],
        ];
    }

    #[Group('known-defect')]
    #[DataProvider('secondForGroszAtThreeCases')]
    public function test_second_for_a_grosz_at_the_real_leaflet_threshold(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::SecondForFixed,
            'regular_price' => '89.99',
            'required_quantity' => 3,
            'second_item_price' => '0.01',
        ], $quantity);
    }

    // ---------------------------------------------------------------- conditional unit price

    /**
     * Lidl, verbatim: "cena za 1 opak. przy zakupie 2 opak. lub wielokrotności 2 opak." against a
     * regular 17,99 (context/research/vision.md:104). The promo price is synthetic — see the note
     * on the N=6 case.
     *
     * @return array<string, array{int, string}>
     */
    public static function conditionalUnitPriceAtTwoCases(): array
    {
        return [
            'N=2, qty 1 — promo does not exist below the threshold' => [1, '17.99'],
            'N=2, qty 2 — one full group, every item discounted' => [2, '29.98'],
            'N=2, qty 3 — one group plus a remainder at shelf price' => [3, '47.97'],
            'N=2, qty 4 — two full groups' => [4, '59.96'],
        ];
    }

    #[DataProvider('conditionalUnitPriceAtTwoCases')]
    public function test_conditional_unit_price_at_threshold_two(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '17.99',
            'promo_price' => '14.99',
            'required_quantity' => 2,
        ], $quantity);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function conditionalUnitPriceAtThreeCases(): array
    {
        return [
            'N=3, qty 2 — below the threshold, shelf price' => [2, '12.00'],
            'N=3, qty 3 — one full group at 4,00 each' => [3, '12.00'],
            'N=3, qty 4 — one group plus a remainder at 6,00' => [4, '18.00'],
            'N=3, qty 6 — two full groups' => [6, '24.00'],
        ];
    }

    #[DataProvider('conditionalUnitPriceAtThreeCases')]
    public function test_conditional_unit_price_at_threshold_three(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '6.00',
            'promo_price' => '4.00',
            'required_quantity' => 3,
        ], $quantity);
    }

    /**
     * Lidl, verbatim: "* cena za 1 opak. przy zakupie 6 opak." / "lub wielokrotności 6 opak." /
     * "Cena poza promocją: 3,30/opak." (tests/Fixtures/Ingestion/lidl-tiles.txt:16-22).
     *
     * The THRESHOLD is real; the promo price of 1,99 is SYNTHETIC. Lidl never prints the
     * conditional unit price, so every ingested row of this mechanic carries a null promo_price —
     * the shape asserted in test_a_conditional_unit_price_without_a_promo_price_is_unpriceable().
     * This case therefore proves the arithmetic at a real threshold, not a real offer.
     *
     * @return array<string, array{int, string}>
     */
    public static function conditionalUnitPriceAtSixCases(): array
    {
        return [
            'N=6, qty 1 — promo does not exist below the threshold' => [1, '3.30'],
            'N=6, qty 5 — one short of the group, all at shelf price' => [5, '16.50'],
            'N=6, qty 6 — one full group at 1,99 each' => [6, '11.94'],
            'N=6, qty 7 — one group plus a remainder at 3,30' => [7, '15.24'],
            'N=6, qty 12 — two full groups' => [12, '23.88'],
        ];
    }

    #[DataProvider('conditionalUnitPriceAtSixCases')]
    public function test_conditional_unit_price_at_threshold_six(int $quantity, string $expected): void
    {
        $this->assertTillTotal($expected, [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '3.30',
            'promo_price' => '1.99',
            'required_quantity' => 6,
        ], $quantity);
    }

    // ---------------------------------------------------------------- unpriceable rows

    /**
     * A quantity the domain cannot price honestly must yield nothing rather than a number.
     *
     * @return array<string, array{int}>
     */
    public static function impossibleQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('impossibleQuantities')]
    public function test_an_impossible_quantity_is_unpriceable(int $quantity): void
    {
        $cost = (new PromoCalculator)->cost($this->entry([
            'promo_type' => PromoType::None,
            'regular_price' => '9.99',
        ]), $quantity);

        $this->assertNull($cost);
    }

    /**
     * The state every real Lidl conditional row is in today: the leaflet states the threshold but
     * never prints the discounted unit price, so the parser leaves it null and the row must be
     * unpriceable rather than guessed at.
     */
    public function test_a_conditional_unit_price_without_a_promo_price_is_unpriceable(): void
    {
        $cost = (new PromoCalculator)->cost($this->entry([
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '3.30',
            'promo_price' => null,
            'required_quantity' => 6,
        ]), 6);

        $this->assertNull($cost, 'A conditional row with no promo price must surface as "brak danych".');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedConditionalRows(): array
    {
        return [
            'one_plus_one with no threshold' => [[
                'promo_type' => PromoType::OnePlusOne,
                'regular_price' => '3.49',
                'required_quantity' => null,
                'second_item_price' => '0.00',
            ]],
            'one_plus_one with no second-item price' => [[
                'promo_type' => PromoType::OnePlusOne,
                'regular_price' => '3.49',
                'required_quantity' => 2,
                'second_item_price' => null,
            ]],
            'second_for_fixed with a threshold of 1, which buys nothing' => [[
                'promo_type' => PromoType::SecondForFixed,
                'regular_price' => '4.99',
                'required_quantity' => 1,
                'second_item_price' => '0.01',
            ]],
            'simple with no promo price' => [[
                'promo_type' => PromoType::Simple,
                'regular_price' => '5.99',
                'promo_price' => null,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('malformedConditionalRows')]
    public function test_a_malformed_row_is_unpriceable_rather_than_guessed(array $attributes): void
    {
        $this->assertNull((new PromoCalculator)->cost($this->entry($attributes), 2));
    }

    // ---------------------------------------------------------------- overbuy disclosure

    /**
     * The flag means "the advertised promo did not fire, and reaching it would mean buying more" —
     * it is a disclosure obligation, never a licence to add units to the total.
     *
     * The N=6 / qty 7 case is the one that matters most: a remainder exists, but a full group DID
     * fire, so the shopper is not short of anything and the flag must stay down.
     *
     * @return array<string, array{array<string, mixed>, int, bool}>
     */
    public static function overbuyDisclosureCases(): array
    {
        $conditionalAtThree = [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '6.00',
            'promo_price' => '4.00',
            'required_quantity' => 3,
        ];

        $conditionalAtSix = [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'regular_price' => '3.30',
            'promo_price' => '1.99',
            'required_quantity' => 6,
        ];

        $onePlusOne = [
            'promo_type' => PromoType::OnePlusOne,
            'regular_price' => '3.49',
            'required_quantity' => 2,
            'second_item_price' => '0.00',
        ];

        return [
            'conditional N=3, qty 2 — one short, promo did not fire' => [$conditionalAtThree, 2, true],
            'conditional N=3, qty 3 — promo fired' => [$conditionalAtThree, 3, false],
            'conditional N=6, qty 5 — one short, promo did not fire' => [$conditionalAtSix, 5, true],
            'conditional N=6, qty 7 — a group fired; a remainder is not a shortfall' => [$conditionalAtSix, 7, false],
            '1+1 at qty 1 — the pair never formed' => [$onePlusOne, 1, true],
            '1+1 at qty 2 — the pair formed' => [$onePlusOne, 2, false],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('overbuyDisclosureCases')]
    public function test_overbuy_is_disclosed_when_a_promo_did_not_fire(array $attributes, int $quantity, bool $expected): void
    {
        $cost = (new PromoCalculator)->cost($this->entry($attributes), $quantity);

        $this->assertNotNull($cost);
        $this->assertSame($expected, $cost->promoRequiredMoreItems);
    }
}
