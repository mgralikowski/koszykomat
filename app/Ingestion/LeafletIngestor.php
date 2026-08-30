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
            return IngestionSummary::empty($networkSlug)->withNote("no chain configured as '{$networkSlug}'");
        }

        $flyers = $this->firstSuccess(
            $config['discoverers'] ?? [],
            fn (Discoverer $driver): array => $driver->discover(),
        );

        if ($flyers === []) {
            return IngestionSummary::empty($networkSlug)->withNote('discovery returned no leaflet');
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

        $assets = $this->firstSuccess($acquirers, fn (Acquirer $driver): array => $driver->acquire($flyer));

        if ($assets === []) {
            return IngestionSummary::empty($flyer->networkSlug)->withNote('nothing could be downloaded');
        }

        $kinds = array_unique(array_map(fn (Asset $asset): string => $asset->kind, $assets));

        $parsers = array_values(array_filter(
            $this->resolveAll($config['parsers'] ?? []),
            fn (Parser $driver): bool => array_intersect($driver->accepts(), $kinds) !== [],
        ));

        $offers = $this->firstSuccess($parsers, fn (Parser $driver): array => $driver->parse($assets));

        return $this->persist($flyer, $offers, $dryRun);
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
            return IngestionSummary::empty($flyer->networkSlug)
                ->withNote("network '{$flyer->networkSlug}' is not in the database")
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
     * @param  list<class-string>|list<object>  $drivers
     * @return list<mixed>
     */
    private function firstSuccess(array $drivers, callable $run): array
    {
        foreach ($drivers as $driver) {
            $instance = is_string($driver) ? app($driver) : $driver;

            try {
                $result = $run($instance);

                if ($result !== []) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Ingestion driver failed', [
                    'driver' => $instance::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [];
    }

    public function pruneAssets(): int
    {
        return $this->assets->prune();
    }
}
