<?php

namespace App\Ingestion\Drivers\Lidl;

use App\Ingestion\Asset;
use App\Ingestion\AssetStore;
use App\Ingestion\Contracts\Acquirer;
use App\Ingestion\Flyer;
use Illuminate\Support\Facades\Http;

/**
 * Downloads the leaflet PDF the flyer API points at (~32 MB for a 95-page leaflet).
 *
 * Streamed to disk rather than held in memory: this runs synchronously inside a CLI process on a
 * shared box, and buffering tens of megabytes per leaflet is exactly the kind of thing that starves
 * everything else on the machine.
 */
final readonly class LidlApiPdfAcquirer implements Acquirer
{
    public function __construct(private AssetStore $store) {}

    public function canHandle(Flyer $flyer): bool
    {
        return filled($flyer->meta['pdf_url'] ?? null);
    }

    /**
     * @return list<Asset>
     */
    public function acquire(Flyer $flyer): array
    {
        $url = $flyer->meta['pdf_url'];
        $path = $this->store->directoryFor($flyer).'/leaflet.pdf';

        $response = Http::timeout(180)->sink($path)->get($url);

        if (! $response->successful() || ! is_file($path) || filesize($path) === 0) {
            return [];
        }

        return [new Asset($flyer, Asset::KIND_PDF, $path, sourceUrl: $url)];
    }
}
