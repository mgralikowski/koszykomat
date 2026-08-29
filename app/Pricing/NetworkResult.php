<?php

namespace App\Pricing;

use App\Models\Network;

/**
 * One chain's side of the comparison within one scenario.
 *
 * `total` is null whenever anything could not be priced: a partial sum would look like a real
 * basket cost while describing a smaller basket, so there deliberately is no such number.
 */
final readonly class NetworkResult
{
    /**
     * @param  array<string, LinePrice>  $lines  keyed by canonical product slug
     * @param  list<string>  $unpricedProducts  canonical product slugs with no usable price here
     */
    public function __construct(
        public Network $network,
        public array $lines,
        public array $unpricedProducts,
        public ?Money $total,
    ) {}

    public function isComplete(): bool
    {
        return $this->unpricedProducts === [];
    }

    public function lineFor(string $productSlug): ?LinePrice
    {
        return $this->lines[$productSlug] ?? null;
    }
}
