<?php

namespace App\Pricing;

use App\Enums\PromoType;

/**
 * What one price entry costs for a given quantity, and why.
 *
 * The "why" matters as much as the number: the report has to explain which mechanic produced
 * the price, and has to be honest when a headline promo did not apply at all because the basket
 * asks for fewer items than the promo demands.
 */
final readonly class LineCost
{
    public function __construct(
        public Money $total,
        public PromoType $appliedPromo,
        /**
         * True when the entry advertises a conditional mechanic that did not fire because the
         * requested quantity is below its required quantity — the shopper is paying the regular
         * price and would have to overbuy to reach the advertised one.
         */
        public bool $promoRequiredMoreItems,
    ) {}
}
