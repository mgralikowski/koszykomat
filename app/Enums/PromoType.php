<?php

namespace App\Enums;

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
}
