<?php

namespace App\Ingestion;

/**
 * What one ingestion run did, in the four numbers that matter.
 *
 * `matched` sitting far below `parsed` is normal and expected: the pairing map declares a handful of
 * canonical products and everything else on the leaflet is deliberately ignored. `flagged` above
 * zero is the signal worth acting on — those rows exist but no verdict can see them.
 *
 * `notes` and `failures` are deliberately two channels, not one. A note describes a run that did
 * less than hoped but did it correctly — a chain with no current leaflet is not broken. A failure
 * is an operator's problem: a crashed driver, a chain that is not configured, a network missing
 * from the database. Only the second kind may reach the process exit code, because only the second
 * kind stays broken until someone touches it.
 */
final readonly class IngestionSummary
{
    /**
     * @param  list<string>  $notes
     * @param  list<string>  $failures
     */
    public function __construct(
        public string $networkSlug,
        public int $parsed = 0,
        public int $matched = 0,
        public int $written = 0,
        public int $flagged = 0,
        public array $notes = [],
        public array $failures = [],
    ) {}

    public static function empty(string $networkSlug): self
    {
        return new self($networkSlug);
    }

    public function withCounts(int $parsed, int $matched, int $written, int $flagged): self
    {
        return new self($this->networkSlug, $parsed, $matched, $written, $flagged, $this->notes, $this->failures);
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
            $this->failures,
        );
    }

    /**
     * Record something an operator has to fix. Also a note, so the run still reads in order.
     *
     * @param  list<string>  $failures
     */
    public function withFailures(array $failures): self
    {
        if ($failures === []) {
            return $this;
        }

        return new self(
            $this->networkSlug,
            $this->parsed,
            $this->matched,
            $this->written,
            $this->flagged,
            $this->notes,
            [...$this->failures, ...$failures],
        );
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
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
            [...$this->failures, ...$other->failures],
        );
    }
}
