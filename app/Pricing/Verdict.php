<?php

namespace App\Pricing;

use App\Models\Network;

/**
 * The answer to "gdzie taniej" for one scenario — or an explicit refusal to answer.
 *
 * NoData is not a failure mode, it is a product guarantee: when any basket line cannot be priced
 * in some chain, the two baskets are not comparable and naming a winner would be a lie. The
 * verdict says so and lists the products responsible.
 */
final readonly class Verdict
{
    /**
     * @param  list<string>  $missingProducts  canonical product slugs that could not be priced
     */
    private function __construct(
        public VerdictType $type,
        public ?Network $winner = null,
        public ?Money $margin = null,
        public array $missingProducts = [],
    ) {}

    public static function winner(Network $network, Money $margin): self
    {
        return new self(VerdictType::Winner, $network, $margin);
    }

    public static function tie(): self
    {
        return new self(VerdictType::Tie);
    }

    /**
     * @param  list<string>  $missingProducts
     */
    public static function noData(array $missingProducts): self
    {
        return new self(VerdictType::NoData, missingProducts: $missingProducts);
    }

    public function hasWinner(): bool
    {
        return $this->type === VerdictType::Winner;
    }

    public function isNoData(): bool
    {
        return $this->type === VerdictType::NoData;
    }
}
