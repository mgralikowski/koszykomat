<?php

namespace App\Ingestion;

use App\Enums\PromoType;

/**
 * One priced offer as a parser read it — the raw leaflet name, not yet bound to any catalogue product.
 *
 * Named Offer rather than Product because App\Models\Product is the canonical, chain-neutral entity
 * this eventually maps onto; an offer is what a single chain printed on a single page.
 *
 * Money values are strings, deliberately. A parser hands over what it read; deciding whether that is
 * even a number belongs to App\Ingestion\Validation\PriceEntryGate. Constructing Money here would
 * throw on "19,99 zł" — the exact failure the gate exists to turn into a flagged row.
 */
final readonly class Offer
{
    /**
     * @param  array<string, mixed>|null  $sourceBox  the vision model's box_2d crop reference, when it gave one
     */
    public function __construct(
        public string $networkSlug,
        public string $rawName,
        public ?string $regularPrice,
        public PromoType $promoType = PromoType::None,
        public ?string $promoPrice = null,
        public ?int $requiredQuantity = null,
        public ?string $secondItemPrice = null,
        public ?int $pageNumber = null,
        public ?array $sourceBox = null,
        public string $source = 'unknown',
        public ?string $confidence = null,
    ) {}
}
