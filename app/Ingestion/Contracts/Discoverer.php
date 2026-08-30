<?php

namespace App\Ingestion\Contracts;

use App\Ingestion\Flyer;

/**
 * Stage 1: which leaflets does this chain currently publish?
 *
 * Implementations are listed per chain in config/leaflets.php and tried in order, so a chain can
 * carry a fallback (e.g. a scraper behind an API reader) without the engine knowing about it.
 */
interface Discoverer
{
    /**
     * @return list<Flyer>
     */
    public function discover(): array;
}
