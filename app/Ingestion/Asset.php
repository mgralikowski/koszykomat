<?php

namespace App\Ingestion;

/**
 * One downloaded artefact belonging to a leaflet — a PDF, or a single page image.
 *
 * `kind` is what a parser matches on: a PDF-text parser accepts 'pdf', a vision parser accepts
 * 'image'. That is the whole basis of the per-chain parser selection.
 */
final readonly class Asset
{
    public const KIND_PDF = 'pdf';

    public const KIND_IMAGE = 'image';

    public function __construct(
        public Flyer $flyer,
        public string $kind,
        public string $path,
        public ?int $pageNumber = null,
        public ?string $sourceUrl = null,
    ) {}
}
