<?php

namespace App\Ingestion\Drivers\Biedronka;

use App\Ingestion\Asset;
use App\Ingestion\AssetStore;
use App\Ingestion\Contracts\Acquirer;
use App\Ingestion\Flyer;
use Illuminate\Support\Facades\Http;

/**
 * Downloads the leaflet's page images (~2.6 MB each, ~53 pages).
 *
 * One asset per page, because the vision parser bills and reasons per page.
 */
final readonly class BiedronkaApiImageAcquirer implements Acquirer
{
    public function __construct(private AssetStore $store) {}

    public function canHandle(Flyer $flyer): bool
    {
        return filled($flyer->meta['images'] ?? null);
    }

    /**
     * @return list<Asset>
     */
    public function acquire(Flyer $flyer): array
    {
        $directory = $this->store->directoryFor($flyer);
        $assets = [];

        foreach (array_values($flyer->meta['images']) as $index => $url) {
            $pageNumber = $index + 1;
            $path = sprintf('%s/page_%02d.%s', $directory, $pageNumber, $this->extension($url));

            if (! is_file($path) || filesize($path) === 0) {
                $response = Http::timeout(120)->sink($path)->get($url);

                if (! $response->successful()) {
                    continue;
                }
            }

            $assets[] = new Asset($flyer, Asset::KIND_IMAGE, $path, $pageNumber, $url);
        }

        return $assets;
    }

    private function extension(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) ? $extension : 'png';
    }
}
