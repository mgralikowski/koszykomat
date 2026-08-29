<?php

namespace App\Pricing;

use App\Enums\PromoType;

/**
 * Which prices a shopper can actually reach.
 *
 * A loyalty-card price is real but conditional on holding the card, so the comparison is run
 * once for each kind of shopper rather than quietly assuming one of them.
 */
enum Scenario: string
{
    case WithoutCard = 'without_card';
    case WithCard = 'with_card';

    /**
     * Polish label for the report (user-facing string).
     */
    public function label(): string
    {
        return match ($this) {
            self::WithoutCard => 'bez karty',
            self::WithCard => 'z kartą lojalnościową',
        };
    }

    /**
     * Whether a shopper in this scenario can reach a price offered under the given mechanic.
     */
    public function allows(PromoType $promoType): bool
    {
        return $this === self::WithCard || $promoType !== PromoType::LoyaltyCard;
    }
}
