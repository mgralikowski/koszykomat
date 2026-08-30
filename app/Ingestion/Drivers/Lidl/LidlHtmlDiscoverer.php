<?php

namespace App\Ingestion\Drivers\Lidl;

use App\Ingestion\Contracts\Discoverer;
use App\Ingestion\Flyer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lidl's current leaflets, from the listing page's static HTML plus the flyer JSON API.
 *
 * Both are plain HTTP — no browser needed, which is what keeps this whole foundation inside PHP on
 * a VPS with no container runtime.
 */
final readonly class LidlHtmlDiscoverer implements Discoverer
{
    /**
     * @return list<Flyer>
     */
    public function discover(): array
    {
        $config = config('leaflets.chains.lidl');

        $listing = Http::timeout(30)->get($config['discovery_url']);

        if (! $listing->successful()) {
            return [];
        }

        preg_match_all('#/l/pl/gazetki/([a-z0-9-]+)/ar/\d+#i', $listing->body(), $matches);

        $flyers = [];

        foreach (array_unique($matches[1] ?? []) as $slug) {
            if (filled($config['leaflet_slug_pattern'] ?? null) && ! preg_match($config['leaflet_slug_pattern'], $slug)) {
                continue;
            }

            $flyer = $this->describe($slug, $config);

            if ($flyer !== null) {
                $flyers[] = $flyer;
            }
        }

        return $flyers;
    }

    private function describe(string $slug, array $config): ?Flyer
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->get($config['flyer_api'], [
                'flyer_identifier' => $slug,
                'region_id' => $config['region_id'],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $flyer = $response->json('flyer');

        if (! is_array($flyer) || blank($flyer['pdfUrl'] ?? null)) {
            return null;
        }

        $from = $flyer['startDate'] ?? null;
        $to = $flyer['endDate'] ?? null;

        if (blank($from) || blank($to)) {
            return null;
        }

        try {
            // startOfDay() matters: `leaflets.valid_from`/`valid_to` are date columns and
            // Leaflet::validOn() compares against them. F-01's review caught a real bug here
            // where a datetime silently excluded a leaflet's own last valid day.
            $validFrom = Carbon::parse($from)->startOfDay();
            $validTo = Carbon::parse($to)->startOfDay();
        } catch (\Throwable $e) {
            Log::warning('Lidl flyer has unparseable dates', ['slug' => $slug]);

            return null;
        }

        return new Flyer(
            networkSlug: 'lidl',
            externalId: $slug,
            title: $flyer['title'] ?? null,
            validFrom: $validFrom,
            validTo: $validTo,
            meta: ['pdf_url' => $flyer['pdfUrl']],
        );
    }
}
