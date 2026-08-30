<?php

namespace App\Ingestion\Validation;

/**
 * The gate's answer: trusted, or flagged with the reasons why.
 *
 * A flagged row is still stored — it is evidence, and dropping it would hide that the leaflet
 * carried an offer at all. It is stored with `needs_review = true`, which keeps it out of every
 * verdict, so the basket says "brak danych" for that product instead of pricing it from a number
 * nobody checked.
 */
final readonly class GateVerdict
{
    /**
     * @param  list<string>  $reasons
     */
    private function __construct(
        public bool $needsReview,
        public array $reasons = [],
    ) {}

    public static function trusted(): self
    {
        return new self(false);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function flagged(array $reasons): self
    {
        return new self(true, array_values($reasons));
    }

    public function summary(): string
    {
        return implode('; ', $this->reasons);
    }
}
