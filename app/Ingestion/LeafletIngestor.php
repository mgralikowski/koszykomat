<?php

namespace App\Ingestion;

use App\Ingestion\Contracts\Acquirer;
use App\Ingestion\Contracts\Discoverer;
use App\Ingestion\Contracts\Parser;
use App\Ingestion\Validation\PriceEntryCandidate;
use App\Ingestion\Validation\PriceEntryGate;
use App\Models\Leaflet;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Runs Discover → Acquire → Parse for a chain and persists what comes back.
 *
 * Each stage is a list of drivers tried in order until one produces a usable result, so a chain can
 * grow a fallback without this class learning anything. That split, and the per-chain driver lists
 * in config/leaflets.php, are what make a third chain a configuration entry rather than a rewrite.
 *
 * Two invariants hold regardless of chain or driver:
 *
 *  - An offer only becomes a priced row when the pairing map declares which canonical product it is.
 *    Nothing is inferred from names; an unmatched offer is counted and dropped.
 *  - Every candidate row passes App\Ingestion\Validation\PriceEntryGate first, and a row the gate
 *    flags is written with `needs_review = true` — stored as evidence, invisible to every verdict.
 */
final readonly class LeafletIngestor
{
    public function __construct(private PriceEntryGate $gate, private AssetStore $assets) {}

    public function ingest(string $networkSlug, bool $dryRun = false): IngestionSummary
    {
        $config = config("leaflets.chains.{$networkSlug}");

        if (! is_array($config)) {
            // A slug nothing answers to is a typo in the cron entry or in config/leaflets.php, and it
            // will keep ingesting nothing every night until someone reads it. That is a failure, not
            // a note.
            return IngestionSummary::empty($networkSlug)
                ->withFailures(["no chain configured as '{$networkSlug}' — check the cron entry against config/leaflets.php"]);
        }

        $discovery = $this->firstSuccess(
            $config['discoverers'] ?? [],
            fn (Discoverer $driver): array => $driver->discover(),
        );

        /** @var list<Flyer> $flyers */
        $flyers = $discovery->items;

        if ($flyers === []) {
            return IngestionSummary::empty($networkSlug)
                ->withNote('discovery returned no leaflet')
                ->withFailures($discovery->failures);
        }

        $summary = IngestionSummary::empty($networkSlug);

        foreach ($flyers as $flyer) {
            $summary = $summary->merge($this->ingestFlyer($flyer, $config, $dryRun));
        }

        return $summary;
    }

    private function ingestFlyer(Flyer $flyer, array $config, bool $dryRun): IngestionSummary
    {
        $acquirers = array_values(array_filter(
            $this->resolveAll($config['acquirers'] ?? []),
            fn (Acquirer $driver): bool => $driver->canHandle($flyer),
        ));

        $acquired = $this->firstSuccess($acquirers, fn (Acquirer $driver): array => $driver->acquire($flyer));

        /** @var list<Asset> $assets */
        $assets = $acquired->items;

        if ($assets === []) {
            return IngestionSummary::empty($flyer->networkSlug)
                ->withNote('nothing could be downloaded')
                ->withFailures($acquired->failures);
        }

        $kinds = array_unique(array_map(fn (Asset $asset): string => $asset->kind, $assets));

        $parsers = array_values(array_filter(
            $this->resolveAll($config['parsers'] ?? []),
            fn (Parser $driver): bool => array_intersect($driver->accepts(), $kinds) !== [],
        ));

        $parsed = $this->firstSuccess($parsers, fn (Parser $driver): array => $driver->parse($assets));

        /** @var list<Offer> $offers */
        $offers = $parsed->items;

        return $this->persist($flyer, $offers, $dryRun)->withFailures($parsed->failures);
    }

    /**
     * @param  list<Offer>  $offers
     */
    private function persist(Flyer $flyer, array $offers, bool $dryRun): IngestionSummary
    {
        $parsed = count($offers);
        $matched = 0;
        $written = 0;
        $flagged = 0;

        $network = Network::query()->where('slug', $flyer->networkSlug)->first();

        if ($network === null) {
            // Parsing worked and the rows have nowhere to go: the seed never ran, or the slug was
            // renamed on one side only. Nothing about the next run fixes itself.
            return IngestionSummary::empty($flyer->networkSlug)
                ->withFailures(["network '{$flyer->networkSlug}' is not in the database — nothing can be written"])
                ->withCounts($parsed, 0, 0, 0);
        }

        $leaflet = $dryRun ? null : $this->leafletFor($network, $flyer);

        foreach ($offers as $offer) {
            $slug = $this->canonicalSlugFor($offer);

            if ($slug === null) {
                continue;
            }

            $matched++;

            if ($dryRun) {
                continue;
            }

            $listing = $this->listingFor($network, $slug, $offer);

            $verdict = $this->gate->inspect(new PriceEntryCandidate(
                promoType: $offer->promoType,
                regularPrice: $offer->regularPrice,
                promoPrice: $offer->promoPrice,
                requiredQuantity: $offer->requiredQuantity,
                secondItemPrice: $offer->secondItemPrice,
                networkProductId: $listing->id,
            ));

            if ($verdict->needsReview) {
                $flagged++;
                Log::info('Leaflet offer flagged for review', [
                    'network' => $offer->networkSlug,
                    'name' => $offer->rawName,
                    'reasons' => $verdict->reasons,
                ]);
            }

            // Upsert on the unique key F-01 already defined, so re-ingesting a leaflet updates its
            // rows instead of duplicating them.
            PriceEntry::query()->updateOrCreate(
                [
                    'leaflet_id' => $leaflet->id,
                    'network_product_id' => $listing->id,
                    'promo_type' => $offer->promoType,
                ],
                [
                    // A row the gate rejected keeps exactly what was read. Nulling the parameters
                    // would leave a flagged row that says nothing about why it failed, which
                    // defeats the point of storing it: `needs_review` already makes it invisible to
                    // every verdict, so the raw values are evidence, not a hazard.
                    // regular_price is NOT NULL — an unreadable price becomes 0.00 and stays flagged.
                    'regular_price' => $offer->regularPrice ?? '0.00',
                    'promo_price' => $offer->promoPrice,
                    'required_quantity' => $offer->requiredQuantity,
                    'second_item_price' => $offer->secondItemPrice,
                    'source' => $offer->source,
                    'confidence' => $offer->confidence,
                    'needs_review' => $verdict->needsReview,
                    'source_box' => $offer->sourceBox,
                ],
            );

            $written++;
        }

        return IngestionSummary::empty($flyer->networkSlug)->withCounts($parsed, $matched, $written, $flagged);
    }

    private function leafletFor(Network $network, Flyer $flyer): Leaflet
    {
        return Leaflet::query()->updateOrCreate(
            ['network_id' => $network->id, 'source_reference' => $flyer->externalId],
            [
                'name' => $flyer->title,
                'valid_from' => $flyer->validFrom->toDateString(),
                'valid_to' => $flyer->validTo->toDateString(),
                'source_type' => $flyer->networkSlug.'.ingested',
            ],
        );
    }

    /**
     * The pairing map is the only bridge from a leaflet name to the catalogue — see
     * config/leaflets.php for why nothing is inferred.
     */
    private function canonicalSlugFor(Offer $offer): ?string
    {
        foreach (config('leaflets.pairing', []) as $slug => $definition) {
            foreach ($definition['chains'][$offer->networkSlug]['patterns'] ?? [] as $pattern) {
                if (preg_match($pattern, $offer->rawName)) {
                    return (string) $slug;
                }
            }
        }

        return null;
    }

    private function listingFor(Network $network, string $slug, Offer $offer): NetworkProduct
    {
        $definition = config("leaflets.pairing.{$slug}");
        $chain = $definition['chains'][$offer->networkSlug] ?? [];

        $product = Product::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $definition['name'] ?? $slug],
        );

        return NetworkProduct::query()->updateOrCreate(
            ['network_id' => $network->id, 'product_id' => $product->id],
            [
                'name' => $offer->rawName,
                'brand' => $chain['brand'] ?? null,
                'size_label' => $chain['size_label'] ?? null,
            ],
        );
    }

    /**
     * @param  list<class-string>  $classes
     * @return list<object>
     */
    private function resolveAll(array $classes): array
    {
        return array_map(fn (string $class): object => app($class), $classes);
    }

    /**
     * Try each driver until one returns a non-empty result. A driver that throws is logged and the
     * next one gets its turn — that is the entire point of listing more than one.
     *
     * The catch stays; what changed is that it no longer ends the story. A crash a later driver
     * covered for is logged and forgotten, but when the whole list is exhausted the reasons come
     * back with the empty result, so the caller can tell "every driver died" apart from "there was
     * genuinely nothing to fetch". Swallowing that difference is what let a dead nightly refresh
     * report success to cron.
     *
     * @param  list<class-string>|list<object>  $drivers
     */
    private function firstSuccess(array $drivers, callable $run): StageOutcome
    {
        $failures = [];

        foreach ($drivers as $driver) {
            $instance = is_string($driver) ? app($driver) : $driver;

            try {
                $result = $run($instance);

                if ($result !== []) {
                    return new StageOutcome($result);
                }
            } catch (\Throwable $e) {
                Log::warning('Ingestion driver failed', [
                    'driver' => $instance::class,
                    'error' => $e->getMessage(),
                ]);

                $failures[] = class_basename($instance).' failed: '.$e->getMessage();
            }
        }

        return new StageOutcome([], $failures);
    }

    public function pruneAssets(): int
    {
        return $this->assets->prune();
    }
}
