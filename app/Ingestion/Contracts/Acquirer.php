<?php

namespace App\Ingestion\Contracts;

use App\Ingestion\Asset;
use App\Ingestion\Flyer;

/**
 * Stage 2: fetch a leaflet's content to disk.
 *
 * canHandle() lets a chain declare more than one acquirer and pick per flyer — a leaflet exposing a
 * PDF url takes the PDF path, one exposing only images takes the image path.
 */
interface Acquirer
{
    public function canHandle(Flyer $flyer): bool;

    /**
     * @return list<Asset>
     */
    public function acquire(Flyer $flyer): array;
}
