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

        foreach ($chains as $chain) {
            $this->info("Ingesting {$chain} …");

            $summary = $ingestor->ingest($chain, $dryRun);
            $flaggedTotal += $summary->flagged;

            $rows[] = [$chain, $summary->parsed, $summary->matched, $summary->written, $summary->flagged];

            foreach ($summary->notes as $note) {
                $this->warn("  {$chain}: {$note}");
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

        return self::SUCCESS;
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
