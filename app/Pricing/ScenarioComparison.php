<?php

namespace App\Pricing;

/**
 * The full comparison for one kind of shopper (with or without a loyalty card).
 */
final readonly class ScenarioComparison
{
    /**
     * @param  array<string, NetworkResult>  $results  keyed by network slug
     */
    public function __construct(
        public Scenario $scenario,
        public array $results,
        public Verdict $verdict,
    ) {}

    public function resultFor(string $networkSlug): ?NetworkResult
    {
        return $this->results[$networkSlug] ?? null;
    }
}
