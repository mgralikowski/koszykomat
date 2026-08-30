<?php

namespace App\Ingestion;

use Illuminate\Support\Carbon;

/**
 * One leaflet as the chain describes it, before anything has been downloaded.
 *
 * `externalId` is the chain's own identifier — a Lidl flyer slug or a Biedronka UUID — and it is
 * what makes re-ingestion idempotent: it becomes `leaflets.source_reference`, so a second run of
 * the same leaflet updates rows instead of duplicating them.
 */
final readonly class Flyer
{
    /**
     * @param  array<string, mixed>  $meta  chain-specific extras the acquirer needs (pdf url, image list, …)
     */
    public function __construct(
        public string $networkSlug,
        public string $externalId,
        public ?string $title,
        public Carbon $validFrom,
        public Carbon $validTo,
        public array $meta = [],
    ) {}
}
