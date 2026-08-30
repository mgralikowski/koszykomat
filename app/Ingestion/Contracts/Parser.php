<?php

namespace App\Ingestion\Contracts;

use App\Ingestion\Asset;
use App\Ingestion\Offer;

/**
 * Stage 3: turn downloaded assets into priced offers.
 *
 * accepts() returns the asset kinds this parser understands, which is how the engine matches a
 * parser to what the acquirer actually produced — PDF text for Lidl, vision for Biedronka's images.
 */
interface Parser
{
    /**
     * @return list<string> Asset::KIND_* values
     */
    public function accepts(): array;

    /**
     * @param  list<Asset>  $assets
     * @return list<Offer>
     */
    public function parse(array $assets): array;
}
