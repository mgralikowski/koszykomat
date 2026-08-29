<?php

namespace App\Pricing;

use App\Enums\PromoType;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use Illuminate\Support\Carbon;

/**
 * What one chain charges for one basket line, with everything the report needs to justify it.
 *
 * The listing is carried rather than just its price so the report can show WHAT was compared —
 * brand and gramatura — which is the FR-008 requirement that pairings are always explicit and
 * the user judges comparability themselves.
 */
final readonly class LinePrice
{
    public function __construct(
        public NetworkProduct $listing,
        public int $quantity,
        public PriceEntry $entry,
        public PromoType $appliedPromo,
        public Money $total,
        public Carbon $validFrom,
        public Carbon $validTo,
        public bool $promoRequiredMoreItems,
    ) {}
}
