<?php

namespace App\Enums;

use App\Pricing\Money;

/**
 * The four leaflet promo mechanics required by the PRD (FR-007), plus the no-promo baseline.
 *
 * Each case declares which of the promo parameter columns on `price_entries` must hold a
 * value and which must stay null. That matrix cannot be expressed in portable DDL — MySQL 8
 * (production) and the in-memory SQLite used by the test suite disagree on check constraints —
 * so it is enforced here in application code and asserted by tests over the seeded rows.
 *
 * Money arithmetic contract for every consumer of this enum: prices are stored as
 * DECIMAL(8,2) and cast with `decimal:2`, which hands PHP back *strings* that silently
 * coerce to float in arithmetic. Always compute through App\Pricing\Money; never use raw
 * `+` / `*` on cast values, and never call bc* directly — `bcmath.scale` is 0, so a call
 * without an explicit scale truncates the fractional part silently.
 */
enum PromoType: string
{
    case None = 'none';
    case Simple = 'simple';
    case OnePlusOne = 'one_plus_one';
    case SecondForFixed = 'second_for_fixed';
    case LoyaltyCard = 'loyalty_card';
    /**
     * A per-unit price that only applies from `required_quantity` items up — "cena za 1 opak.
     * przy zakupie 3 opak.". Added in PRD FR-007 v2 after the first real Lidl ingestion showed it
     * is that chain's dominant mechanic: "przy zakupie N" appears 94× in one leaflet, against 25
     * for "gratis" and 8 for "za grosz" combined.
     */
    case ConditionalUnitPrice = 'conditional_unit_price';

    /**
     * Polish label for the comparison report (user-facing string).
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'cena regularna',
            self::Simple => 'cena promocyjna',
            self::OnePlusOne => '1+1 gratis',
            self::SecondForFixed => 'drugi produkt za',
            self::LoyaltyCard => 'cena z kartą',
            self::ConditionalUnitPrice => 'cena przy zakupie wielu szt.',
        };
    }

    /**
     * Parameter columns that must hold a value for this mechanic.
     *
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        return match ($this) {
            self::None => [],
            self::Simple, self::LoyaltyCard => ['promo_price'],
            self::OnePlusOne, self::SecondForFixed => ['required_quantity', 'second_item_price'],
            // The first mechanic to need both: a discounted unit price AND the quantity that
            // unlocks it. Every other mechanic treats these two as mutually exclusive, which is
            // exactly why this matrix lives in PHP rather than in a DDL check constraint.
            self::ConditionalUnitPrice => ['promo_price', 'required_quantity'],
        };
    }

    /**
     * Parameter columns that must stay null for this mechanic.
     *
     * @return list<string>
     */
    public function forbiddenParameters(): array
    {
        return array_values(array_diff(self::parameterColumns(), $this->requiredParameters()));
    }

    /**
     * Whether the mechanic only pays off once the shopper buys `required_quantity` items.
     *
     * These are the mechanics that can force an overbuy, which the report must price as a
     * real cost rather than taking the headline "after promo" price naively.
     */
    public function isConditional(): bool
    {
        return in_array('required_quantity', $this->requiredParameters(), true);
    }

    /**
     * Every promo parameter column on `price_entries`.
     *
     * @return list<string>
     */
    public static function parameterColumns(): array
    {
        return ['promo_price', 'required_quantity', 'second_item_price'];
    }

    /**
     * Whether the parameter *values* are admissible for this mechanic, given the regular price.
     *
     * requiredParameters()/forbiddenParameters() only say which columns must hold a value. That was
     * enough while every row was hand-written in the seeder, but a parser can produce a structurally
     * valid row carrying nonsense — a `one_plus_one` with `required_quantity = 1` buys nothing, and
     * a promo price above the regular price is not a promotion. Those rows are impossible rather
     * than merely unlikely, so they are rejected here without a database round-trip.
     *
     * Returns the reasons the row is inadmissible; an empty list means the values are consistent.
     * Money comparisons go through App\Pricing\Money — never raw operators on `decimal:2` strings.
     *
     * @return list<string>
     */
    public function valueViolations(
        Money $regularPrice,
        ?Money $promoPrice,
        ?int $requiredQuantity,
        ?Money $secondItemPrice,
    ): array {
        $violations = [];

        if ($this === self::Simple || $this === self::LoyaltyCard || $this === self::ConditionalUnitPrice) {
            if ($promoPrice !== null && ! $promoPrice->isLessThan($regularPrice)) {
                $violations[] = 'promo_price is not below regular_price';
            }
        }

        if ($this->isConditional()) {
            if ($requiredQuantity !== null && $requiredQuantity < 2) {
                $violations[] = 'required_quantity must be at least 2 for a conditional mechanic';
            }

            if ($secondItemPrice !== null && $secondItemPrice->isLessThan(Money::zero())) {
                $violations[] = 'second_item_price is negative';
            }
        }

        if ($this === self::OnePlusOne
            && $secondItemPrice !== null
            && ! $secondItemPrice->equals(Money::zero())
        ) {
            $violations[] = 'one_plus_one requires a second_item_price of 0.00 — the second item is free';
        }

        if ($this === self::SecondForFixed && $secondItemPrice !== null) {
            if ($secondItemPrice->equals(Money::zero())) {
                $violations[] = 'second_for_fixed requires a second_item_price above 0.00';
            } elseif (! $secondItemPrice->isLessThan($regularPrice)) {
                $violations[] = 'second_item_price is not below regular_price';
            }
        }

        return $violations;
    }
}
