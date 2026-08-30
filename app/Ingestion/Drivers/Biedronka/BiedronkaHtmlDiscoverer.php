<?php

namespace App\Ingestion\Drivers\Biedronka;

use App\Ingestion\Contracts\Discoverer;
use App\Ingestion\Flyer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Biedronka's current leaflets: listing anchors from static HTML, then the leaflet UUID from the
 * leaflet page, then the leaflet API for pages and dates.
 *
 * Prior art recorded that this needed a browser. It does not any more — verified over plain HTTP —
 * which is what keeps Chromium off a shared VPS that has no container runtime.
 *
 * Biedronka publishes ~13 concurrent leaflets. Only the main food one is ingested: the others
 * multiply vision spend by their count and add nothing a basket comparison can use.
 */
final readonly class BiedronkaHtmlDiscoverer implements Discoverer
{
    /**
     * @return list<Flyer>
     */
    public function discover(): array
    {
        $config = config('leaflets.chains.biedronka');

        $listing = Http::timeout(30)->get($config['discovery_url']);

        if (! $listing->successful()) {
            return [];
        }

        preg_match_all('#/pl/press,id,([a-z0-9]+),title,([a-z0-9-]+)#i', $listing->body(), $matches, PREG_SET_ORDER);

        $candidates = [];

        foreach ($matches as [$path, $id, $slug]) {
            $validFrom = $this->startDateFromSlug($slug, $config['leaflet_title_pattern']);

            // No parsable date in the slug means no trustworthy validity window, and the API
            // carries none either — its payload is images and nothing else. An entry that cannot
            // expire would let a stale price be presented as current, which is the one failure the
            // PRD guardrail exists to prevent, so such a leaflet is skipped rather than guessed at.
            if ($validFrom !== null) {
                $candidates[] = ['path' => $path, 'slug' => $slug, 'from' => $validFrom];
            }
        }

        if ($candidates === []) {
            return [];
        }

        // The most recently started leaflet is the current one; a new "home-od-DD-MM" appears weekly.
        usort($candidates, fn (array $a, array $b): int => $b['from'] <=> $a['from']);

        $flyer = $this->describe(
            $candidates[0]['path'],
            $candidates[0]['slug'],
            $candidates[0]['from'],
            $config,
        );

        return $flyer === null ? [] : [$flyer];
    }

    /**
     * `images_desktop` is a list of `{page, images: [...]}` rather than a list of URLs, and the
     * inner list leads with an empty string before the real asset. Flattening here keeps that shape
     * out of the acquirer, which only ever wants "the image for page N".
     *
     * @param  array<int, mixed>  $payload
     * @return list<string>
     */
    private function pageUrls(array $payload): array
    {
        $urls = [];

        foreach ($payload as $entry) {
            $candidates = is_array($entry) ? ($entry['images'] ?? []) : [$entry];

            foreach (array_reverse((array) $candidates) as $candidate) {
                if (is_string($candidate) && str_starts_with($candidate, 'http')) {
                    $urls[] = $candidate;

                    break;
                }
            }
        }

        return $urls;
    }

    /**
     * Biedronka's leaflet API returns no dates at all, so the start date comes from the slug —
     * "home-od-29-08" starts on 29 August. The year is implied; a date more than a month ahead
     * belongs to last year, not next.
     */
    private function startDateFromSlug(string $slug, string $pattern): ?Carbon
    {
        if (! preg_match($pattern, $slug, $parts)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('d-m-Y', $parts[1].'-'.$parts[2].'-'.today()->year)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->greaterThan(today()->addMonth()) ? $date->subYear() : $date;
    }

    private function describe(string $path, string $slug, Carbon $validFrom, array $config): ?Flyer
    {
        $page = Http::timeout(30)->get('https://www.biedronka.pl'.$path);

        if (! $page->successful()) {
            return null;
        }

        if (! preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $page->body(), $uuid)) {
            return null;
        }

        $api = Http::timeout(30)->acceptJson()->get($config['leaflet_api'].'/'.$uuid[0], ['ctx' => 'web']);

        if (! $api->successful()) {
            return null;
        }

        $images = $this->pageUrls($api->json('images_desktop') ?? []);

        if ($images === []) {
            return null;
        }

        return new Flyer(
            networkSlug: 'biedronka',
            externalId: $uuid[0],
            title: $slug,
            validFrom: $validFrom,
            validTo: $validFrom->copy()->addDays((int) $config['validity_days'] - 1),
            meta: ['images' => array_slice($images, 0, (int) $config['max_pages'])],
        );
    }
}
