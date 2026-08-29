<?php

namespace App\Pricing;

use App\Enums\PromoType;
use App\Models\PriceEntry;

/**
 * Prices one product listing under one promo mechanic — the single place the four FR-007
 * mechanics are encoded.
 *
 * Semantics: the shopper is charged for exactly the quantity they asked for. A conditional
 * mechanic applies to each complete group; any leftover item outside a complete group is charged
 * at the regular price. Nothing is ever added to the basket to "unlock" a promo, so a total never
 * includes an item nobody wanted and never overstates the saving.
 */
final class PromoCalculator
{
    /**
     * Returns null when the entry cannot be priced — a malformed row must make a line
     * unpriceable (which surfaces as "brak danych"), never throw and never guess a number.
     */
    public function cost(PriceEntry $entry, int $quantity): ?LineCost
    {
        if ($quantity < 1) {
            return null;
        }

        $regular = Money::fromDecimalString((string) $entry->regular_price);

        return match ($entry->promo_type) {
            PromoType::None => new LineCost($regular->times($quantity), PromoType::None, false),
            PromoType::Simple, PromoType::LoyaltyCard => $this->flatPromoPrice($entry, $quantity, $regular),
            PromoType::OnePlusOne, PromoType::SecondForFixed => $this->conditional($entry, $quantity, $regular),
        };
    }

    /**
     * Simple promo price and loyalty-card price: a straight per-unit discount.
     *
     * A shopper can always decline a promo and pay the shelf price, so a promo price ABOVE the
     * regular one is never what they would actually pay. Ingestion can write such a row (one
     * misread digit), and on a listing carrying no separate regular-price entry the cheapest-wins
     * pass has nothing to compare it against — without this clamp the basket verdict would be
     * confidently wrong. The mechanic reported alongside it is the one actually charged.
     */
    private function flatPromoPrice(PriceEntry $entry, int $quantity, Money $regular): ?LineCost
    {
        if ($entry->promo_price === null) {
            return null;
        }

        $unit = Money::fromDecimalString((string) $entry->promo_price);

        if ($regular->isLessThan($unit)) {
            return new LineCost($regular->times($quantity), PromoType::None, false);
        }

        return new LineCost($unit->times($quantity), $entry->promo_type, false);
    }

    /**
     * 1+1 gratis and second-for-a-fixed-amount share one formula: within a complete group the
     * shopper pays the regular price once plus the second-item price for every further item in
     * the group; items outside a complete group are charged at the regular price.
     *
     * At required_quantity = 2 that is `regular + 0.00` per pair for 1+1 and `regular + 0.01`
     * for second-for-grosz.
     */
    private function conditional(PriceEntry $entry, int $quantity, Money $regular): ?LineCost
    {
        $requiredQuantity = $entry->required_quantity;

        // required_quantity is nullable at the database level, so a malformed row is reachable
        // once ingestion writes real data. Guarding here also keeps intdiv() away from zero.
        if ($requiredQuantity === null || $requiredQuantity < 2 || $entry->second_item_price === null) {
            return null;
        }

        $secondItemPrice = Money::fromDecimalString((string) $entry->second_item_price);

        // The same clamp flatPromoPrice() applies: a further item inside the group never costs
        // more than the shelf price, whatever a malformed row claims.
        if ($regular->isLessThan($secondItemPrice)) {
            $secondItemPrice = $regular;
        }

        $groups = intdiv($quantity, $requiredQuantity);
        $remainder = $quantity % $requiredQuantity;

        $groupCost = $regular->plus($secondItemPrice->times($requiredQuantity - 1));

        $total = $groupCost->times($groups)->plus($regular->times($remainder));

        return new LineCost($total, $entry->promo_type, $groups === 0);
    }
}
