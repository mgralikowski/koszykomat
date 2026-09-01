<?php

namespace App\Console\Commands;

use App\Ingestion\AssetStore;
use App\Ingestion\LeafletIngestor;
use Illuminate\Console\Command;

/**
 * Manual trigger for leaflet ingestion (FR-006).
 *
 * Deliberately synchronous and deliberately thin: there is no queue worker provisioned on the
 * production box, so a dispatched job would be written to the `jobs` table and never run — silently.
 * All logic lives in App\Ingestion\LeafletIngestor, which keeps it testable without an artisan call
 * and leaves the door open for the scheduler entry S-04 will add.
 */
class IngestLeaflets extends Command
{
    protected $signature = 'leaflets:ingest
                            {network? : Chain slug to ingest; all configured chains when omitted}
                            {--dry-run : Discover, download and parse, but write nothing}';

    protected $description = 'Fetch the current leaflets and turn them into priced rows';

    public function handle(LeafletIngestor $ingestor, AssetStore $assets): int
    {
        $chains = $this->argument('network')
            ? [$this->argument('network')]
            : array_keys(config('leaflets.chains', []));

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run — nothing will be written.');
        }

        $rows = [];
        $flaggedTotal = 0;
        $failures = [];

        foreach ($chains as $chain) {
            $this->info("Ingesting {$chain} …");

            $summary = $ingestor->ingest($chain, $dryRun);
            $flaggedTotal += $summary->flagged;

            $rows[] = [$chain, $summary->parsed, $summary->matched, $summary->written, $summary->flagged];

            foreach ($summary->notes as $note) {
                $this->warn("  {$chain}: {$note}");
            }

            foreach ($summary->failures as $failure) {
                $failures[] = "{$chain}: {$failure}";
            }
        }

        $this->newLine();
        $this->table(['chain', 'parsed', 'matched', 'written', 'flagged'], $rows);

        if ($flaggedTotal > 0) {
            $this->warn("{$flaggedTotal} row(s) failed validation and are stored as needs_review — no verdict will use them.");
        }

        if (! $dryRun) {
            $pruned = $ingestor->pruneAssets();

            if ($pruned > 0) {
                $this->line("Pruned {$pruned} expired leaflet asset director".($pruned === 1 ? 'y' : 'ies').'.');
            }

            $this->reportDiskSpace($assets);
        }

        // Housekeeping above still runs on a failed refresh — expired assets are worth pruning
        // whether or not new ones arrived — but the run does not get to call itself a success.
        if ($failures !== []) {
            return $this->reportFailure($failures);
        }

        return self::SUCCESS;
    }

    /**
     * Report a refresh that did not refresh anything.
     *
     * This exit code is the whole alarm. The production cron (deploy/SERVER-SETUP.md §7) ends in
     * `>> /dev/null 2>&1`, so every line printed above is discarded; the scheduler's
     * `emailOutputOnFailure` and any exit-code monitor are what remain, and both read this. Before
     * it existed, a chain whose drivers all crashed printed a warning into /dev/null and returned
     * SUCCESS — the refresh looked healthy for as long as nobody noticed the prices had stopped
     * moving, which is the one failure the PRD guardrail cannot cover from the data side.
     *
     * @param  list<string>  $failures
     */
    private function reportFailure(array $failures): int
    {
        $this->newLine();

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $this->error('Refresh incomplete — the prices these chains serve are now as old as their last good run.');

        return self::FAILURE;
    }

    /**
     * The risk register asks for a disk check in the ingestion job: leaflet images share the volume
     * MySQL writes to, and a full disk takes the database down with it.
     */
    private function reportDiskSpace(AssetStore $assets): void
    {
        $free = $assets->freeBytes();

        if ($free === null) {
            return;
        }

        $freeGb = $free / 1024 ** 3;

        if ($freeGb < 2.0) {
            $this->error(sprintf('Only %.1f GB free on the leaflet volume — MySQL writes here too.', $freeGb));

            return;
        }

        $this->line(sprintf('Disk: %.1f GB free.', $freeGb));
    }
}
