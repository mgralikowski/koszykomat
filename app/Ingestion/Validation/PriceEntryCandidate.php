<?php

namespace App\Ingestion\Validation;

use App\Enums\PromoType;

/**
 * One parsed price on its way into `price_entries`, before anything has decided to trust it.
 *
 * Money values are carried as strings rather than Money objects on purpose: a parser hands over
 * whatever it read, and deciding whether that string is even a number is the gate's job. Handing
 * a raw model string to Money would throw, which is precisely the failure this shape prevents.
 */
final readonly class PriceEntryCandidate
{
    public function __construct(
        public PromoType $promoType,
        public ?string $regularPrice,
        public ?string $promoPrice = null,
        public ?int $requiredQuantity = null,
        public ?string $secondItemPrice = null,
        /** Null when the listing does not exist yet, which skips the cross-leaflet check. */
        public ?int $networkProductId = null,
    ) {}
}
