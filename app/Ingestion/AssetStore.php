<?php

namespace App\Ingestion;

use App\Models\Leaflet;
use Illuminate\Support\Facades\File;

/**
 * Where downloaded leaflets live, and how they stop accumulating.
 *
 * Assets are kept rather than discarded so a parse can be re-run and a flagged price can be looked
 * at without another 32 MB download. The cost is disk, on a shared box where MySQL writes to the
 * same volume — `infrastructure.md` lists a full disk as a live risk with a concrete failure story
 * behind it. Hence the retention window and the free-space report: a weekly cycle is roughly
 * 170 MB (a ~32 MB Lidl PDF plus ~138 MB of Biedronka pages), so two months settles near 1.5 GB.
 *
 * Pruning is driven by the `leaflets` table rather than by directory timestamps, so what gets
 * deleted is decided by the data's own validity window, not by when a file happened to be touched.
 */
final class AssetStore
{
    public function __construct(private readonly string $root, private readonly int $retentionMonths) {}

    public static function fromConfig(): self
    {
        return new self(
            storage_path('app/leaflets'),
            (int) config('leaflets.retention_months', 2),
        );
    }

    /**
     * The directory a flyer's assets belong in, created on demand.
     */
    public function directoryFor(Flyer $flyer): string
    {
        $path = $this->root.'/'.$flyer->networkSlug.'/'.$this->slug($flyer->externalId);

        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * Delete asset directories for leaflets whose validity window closed more than the retention
     * window ago. Returns how many directories were removed.
     */
    public function prune(): int
    {
        if (! File::isDirectory($this->root)) {
            return 0;
        }

        $cutoff = today()->subMonths($this->retentionMonths);

        $keep = Leaflet::query()
            ->where('valid_to', '>=', $cutoff)
            ->whereNotNull('source_reference')
            ->pluck('source_reference')
            ->map(fn (string $reference): string => $this->slug($reference))
            ->all();

        $removed = 0;

        foreach (File::directories($this->root) as $networkDirectory) {
            foreach (File::directories($networkDirectory) as $flyerDirectory) {
                if (! in_array(basename($flyerDirectory), $keep, true)) {
                    File::deleteDirectory($flyerDirectory);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Free space on the volume the store writes to, in bytes, or null when it cannot be read.
     *
     * The nightly job is supposed to warn before the disk fills rather than after MySQL starts
     * failing writes — the risk register asks for exactly this check.
     */
    public function freeBytes(): ?int
    {
        File::ensureDirectoryExists($this->root);

        $free = @disk_free_space($this->root);

        return $free === false ? null : (int) $free;
    }

    /**
     * External ids carry slashes and commas (Biedronka's `press,id,…`); a directory name must not.
     */
    private function slug(string $externalId): string
    {
        return substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', $externalId) ?? 'unknown', 0, 120);
    }
}
