<?php

namespace App\Pricing;

use App\Models\Product;

/**
 * One line of the requested basket: a canonical product slug and how many the shopper wants.
 *
 * `product` is null when the slug is in the basket configuration but absent from the database.
 * The line still exists so the report can name what is missing rather than silently shrinking
 * the basket — which would let a verdict be computed over something the shopper never asked for.
 */
final readonly class BasketLine
{
    public function __construct(
        public string $slug,
        public int $quantity,
        public ?Product $product = null,
    ) {}

    /**
     * The canonical product name, falling back to the slug when the product is unknown.
     */
    public function name(): string
    {
        return $this->product?->name ?? $this->slug;
    }
}
