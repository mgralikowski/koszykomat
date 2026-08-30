<?php

namespace App\Ingestion;

/**
 * What one ingestion run did, in the four numbers that matter.
 *
 * `matched` sitting far below `parsed` is normal and expected: the pairing map declares a handful of
 * canonical products and everything else on the leaflet is deliberately ignored. `flagged` above
 * zero is the signal worth acting on — those rows exist but no verdict can see them.
 */
final readonly class IngestionSummary
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $networkSlug,
        public int $parsed = 0,
        public int $matched = 0,
        public int $written = 0,
        public int $flagged = 0,
        public array $notes = [],
    ) {}

    public static function empty(string $networkSlug): self
    {
        return new self($networkSlug);
    }

    public function withCounts(int $parsed, int $matched, int $written, int $flagged): self
    {
        return new self($this->networkSlug, $parsed, $matched, $written, $flagged, $this->notes);
    }

    public function withNote(string $note): self
    {
        return new self(
            $this->networkSlug,
            $this->parsed,
            $this->matched,
            $this->written,
            $this->flagged,
            [...$this->notes, $note],
        );
    }

    public function merge(self $other): self
    {
        return new self(
            $this->networkSlug,
            $this->parsed + $other->parsed,
            $this->matched + $other->matched,
            $this->written + $other->written,
            $this->flagged + $other->flagged,
            [...$this->notes, ...$other->notes],
        );
    }
}
