<?php

namespace App\Pricing;

use Illuminate\Support\Carbon;

/**
 * The complete answer for one basket on one date: both shopper scenarios, plus the basket that
 * was asked about so the report can list lines even when nothing could price them.
 */
final readonly class ComparisonReport
{
    /**
     * @param  list<BasketLine>  $basketLines  in the order the basket declared them
     */
    public function __construct(
        public ScenarioComparison $withoutCard,
        public ScenarioComparison $withCard,
        public array $basketLines,
        public Carbon $comparedOn,
    ) {}

    /**
     * Whether holding a loyalty card changes the answer — a different winner, a different margin,
     * or data that only one scenario can price.
     *
     * This is what decides whether the page shows one verdict or two: presenting two identical
     * verdicts would be noise, and presenting one when they differ would be a lie to half the
     * readers.
     */
    public function cardChangesOutcome(): bool
    {
        $withCard = $this->withCard->verdict;
        $withoutCard = $this->withoutCard->verdict;

        if ($withCard->type !== $withoutCard->type) {
            return true;
        }

        if ($withCard->winner?->slug !== $withoutCard->winner?->slug) {
            return true;
        }

        if ($withCard->margin === null || $withoutCard->margin === null) {
            return $withCard->margin !== $withoutCard->margin;
        }

        return ! $withCard->margin->equals($withoutCard->margin);
    }

    /**
     * The scenario to lead with when the card makes no difference.
     */
    public function headline(): ScenarioComparison
    {
        return $this->withoutCard;
    }
}
