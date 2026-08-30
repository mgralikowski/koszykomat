<?php

namespace App\Ingestion\Drivers\Lidl;

use App\Enums\PromoType;
use App\Ingestion\Asset;
use App\Ingestion\Contracts\Parser;
use App\Ingestion\Offer;
use App\Ingestion\PriceText;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Reads Lidl's leaflet from the PDF's own text layer — no OCR, no model, no hallucination surface.
 *
 * The subtlety that decides whether this works: a leaflet page is a *grid of product tiles*, and a
 * PDF text layer has reading order, not layout. Concatenating a page with getText() interleaves
 * unrelated tiles, so any price picked from "text near the product name" belongs to some other
 * product about as often as not — and the result looks entirely plausible, which makes it the worst
 * kind of wrong. Measured on the current leaflet, that approach reported 89,99 for a coffee priced
 * at 69,99 and attached a neighbouring tile's promo mechanic to a chocolate.
 *
 * So this parser works from getDataTm(), which returns each text fragment with its position, and
 * rebuilds the tiles: fragments are grouped into columns by x, then split into tiles wherever a
 * vertical gap says one product ended and the next began.
 *
 * Even then, only Lidl's own labelling is trusted. It labels the regular price ("Cena poza
 * promocją: 3,30/opak.", ~100× per leaflet) and the purchase condition ("przy zakupie 6 opak.",
 * ~94×), and both sit inside the description tile. It does NOT label the promotional price: that
 * exists only as large type, set apart from the description and landing in a different tile. So
 * this parser reads what is labelled and nothing else. Offers therefore arrive without a
 * promotional price, the validation gate flags them, and the verdict says "brak danych" — which is
 * the honest answer when the leaflet cannot be read, and strictly better than the alternative
 * measured here: plausible prices belonging to the neighbouring product.
 */
final readonly class PdfTextParser implements Parser
{
    /**
     * Fragments within this many points horizontally belong to the same column. Leaflet tiles on
     * the current layout sit on columns roughly 240 points apart, so half that separates them
     * without splitting a tile whose price is set slightly off its name's left edge.
     */
    private const COLUMN_TOLERANCE = 110.0;

    /**
     * A vertical gap larger than this inside a column means a new tile. Within one tile the brand,
     * name, size and price sit ~20-80 points apart; the space between tiles is far wider.
     */
    private const TILE_GAP = 130.0;

    public function __construct(private PdfParser $pdf) {}

    /**
     * @return list<string>
     */
    public function accepts(): array
    {
        return [Asset::KIND_PDF];
    }

    /**
     * @param  list<Asset>  $assets
     * @return list<Offer>
     */
    public function parse(array $assets): array
    {
        $offers = [];

        foreach ($assets as $asset) {
            if ($asset->kind !== Asset::KIND_PDF) {
                continue;
            }

            foreach ($this->tilesByPage($asset) as $pageNumber => $tiles) {
                foreach ($tiles as $tile) {
                    $offer = $this->offerFromTile($tile, $pageNumber);

                    if ($offer !== null) {
                        $offers[] = $offer;
                    }
                }
            }
        }

        return $offers;
    }

    /**
     * @return array<int, list<string>> page number => tile texts
     */
    private function tilesByPage(Asset $asset): array
    {
        try {
            // smalot/pdfparser is pure PHP on purpose: pdftotext is absent from the ddev image and
            // installing binaries on the shared DirectAdmin box is a human-approved change.
            $document = $this->pdf->parseFile($asset->path);
        } catch (\Throwable $e) {
            // A malformed PDF must not abort the run — the other chain still has work to do.
            Log::warning('Lidl PDF could not be parsed', ['path' => $asset->path, 'error' => $e->getMessage()]);

            return [];
        }

        $pages = [];

        foreach ($document->getPages() as $index => $page) {
            try {
                $pages[$index + 1] = $this->tiles($page->getDataTm());
            } catch (\Throwable $e) {
                Log::warning('Lidl page produced no positioned text', ['page' => $index + 1]);
            }
        }

        return $pages;
    }

    /**
     * Rebuild the page's product tiles from positioned text fragments.
     *
     * @param  array<int, array{0: array<int, mixed>, 1: string}>  $fragments
     * @return list<string>
     */
    private function tiles(array $fragments): array
    {
        $positioned = [];

        foreach ($fragments as $fragment) {
            // Invalid byte sequences reach here from the PDF's font encodings, and every preg_* call
            // with /u then returns zero matches WITHOUT throwing — a silent no-op that reads exactly
            // like "this page has no offers". Sanitise once, at the boundary.
            $text = trim(mb_convert_encoding((string) ($fragment[1] ?? ''), 'UTF-8', 'UTF-8'));

            if ($text === '') {
                continue;
            }

            $positioned[] = [
                'x' => (float) ($fragment[0][4] ?? 0),
                'y' => (float) ($fragment[0][5] ?? 0),
                'text' => $text,
            ];
        }

        if ($positioned === []) {
            return [];
        }

        return $this->groupIntoTiles($this->groupIntoColumns($positioned));
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $positioned
     * @return list<list<array{x: float, y: float, text: string}>>
     */
    private function groupIntoColumns(array $positioned): array
    {
        usort($positioned, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        $columns = [];
        $current = [];
        $anchor = null;

        foreach ($positioned as $fragment) {
            if ($anchor === null || abs($fragment['x'] - $anchor) <= self::COLUMN_TOLERANCE) {
                $anchor ??= $fragment['x'];
                $current[] = $fragment;

                continue;
            }

            $columns[] = $current;
            $current = [$fragment];
            $anchor = $fragment['x'];
        }

        if ($current !== []) {
            $columns[] = $current;
        }

        return $columns;
    }

    /**
     * @param  list<list<array{x: float, y: float, text: string}>>  $columns
     * @return list<string>
     */
    private function groupIntoTiles(array $columns): array
    {
        $tiles = [];

        foreach ($columns as $column) {
            // Top of the page downwards, which is the order a tile's brand / name / size / price
            // are printed in.
            usort($column, fn (array $a, array $b): int => $b['y'] <=> $a['y']);

            $tile = [];
            $previousY = null;

            foreach ($column as $fragment) {
                if ($previousY !== null && ($previousY - $fragment['y']) > self::TILE_GAP) {
                    $tiles[] = $this->join($tile);
                    $tile = [];
                }

                $tile[] = $fragment['text'];
                $previousY = $fragment['y'];
            }

            if ($tile !== []) {
                $tiles[] = $this->join($tile);
            }
        }

        return array_values(array_filter($tiles, fn (string $text): bool => $text !== ''));
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines): string
    {
        return trim(implode("\n", $lines));
    }

    /**
     * Only products the pairing map declares are looked for; everything else on the page is ignored
     * by design, since an offer with no declared canonical product could not be written anyway.
     */
    private function offerFromTile(string $tile, int $pageNumber): ?Offer
    {
        foreach (config('leaflets.pairing', []) as $definition) {
            foreach ($definition['chains']['lidl']['patterns'] ?? [] as $pattern) {
                if (preg_match($pattern, $tile, $matches)) {
                    return $this->read($tile, trim($matches[0]), $pageNumber);
                }
            }
        }

        return null;
    }

    private function read(string $tile, string $rawName, int $pageNumber): ?Offer
    {
        // The anchor is a *labelled* price, not the largest number nearby.
        //
        // A leaflet page sets the headline price in large type positioned away from its product
        // description, so the two land in different tiles and no amount of grouping reliably
        // reunites them. What does sit inside the description tile is Lidl's own labelling —
        // "Cena poza promocją: 3,30/opak." appears ~100× per leaflet and "przy zakupie N" ~94×.
        // Reading only those means fewer offers, but every one of them is a number the leaflet
        // explicitly attached to this product. An offer whose regular price is not labelled is
        // skipped: a missing offer degrades to "brak danych", a guessed one is a false verdict.
        $regular = $this->labelledRegularPrice($tile);

        if ($regular === null) {
            return null;
        }

        $promoType = $this->mechanic($tile);
        $requiredQuantity = $this->requiredQuantity($tile);

        // "cena za 1 opak. przy zakupie N opak." is its own mechanic — the price applies to every
        // item once N are bought, which is neither a straight discount nor a price for the next item.
        //
        // A printed purchase condition outranks a promo badge, for the same reason a labelled price
        // outranks a nearby number: the condition is Lidl's own words about THIS product, while a
        // "gratis" badge can drift in from a merged neighbouring tile. Reading it the other way
        // priced three butters at one butter's price — trusted, and wrong by a złoty. The one
        // exception is "za grosz"/"za złotówkę", which names its own second-item price explicitly
        // and is therefore self-describing.
        if ($this->statesPurchaseCondition($tile) && $promoType !== PromoType::SecondForFixed) {
            $promoType = PromoType::ConditionalUnitPrice;
        }

        $promoPrice = null;
        $secondItemPrice = null;

        // Deliberately left null for every mechanic. Lidl labels the regular price
        // ("Cena poza promocją: 3,30/opak.") and the condition ("przy zakupie 6 opak."), but never
        // the promotional price — that exists only as large type, typographically separated from
        // the description and therefore in a different tile. Reading "the cheapest nearby amount"
        // produced numbers that looked right and belonged to the neighbouring product: 64,99 from
        // a second coffee attached to a first. A row with no promo price is flagged by the
        // validation gate and surfaces as "brak danych", which is what the PRD requires when the
        // data cannot be read — a missing offer is recoverable, a wrong price is not.

        if ($promoType === PromoType::OnePlusOne || $promoType === PromoType::SecondForFixed) {
            $secondItemPrice = $promoType === PromoType::OnePlusOne ? '0.00' : $this->fixedSecondPrice($tile);
        }

        return new Offer(
            networkSlug: 'lidl',
            rawName: $rawName,
            regularPrice: $regular,
            promoType: $promoType,
            promoPrice: $promoPrice,
            requiredQuantity: $promoType->isConditional() ? $requiredQuantity : null,
            secondItemPrice: $secondItemPrice,
            pageNumber: $pageNumber,
            source: 'lidl.pdf_text',
            confidence: '1.00',
        );
    }

    /**
     * Lidl's own label for the undiscounted price. "Najniższa cena z 30 dni" is the legally mandated
     * omnibus price and is deliberately NOT accepted here — it is a different number about a
     * different period, and reading it as the regular price would understate every discount.
     */
    private function labelledRegularPrice(string $tile): ?string
    {
        $patterns = [
            '/cena\s+poza\s+promocją[:\s]*([\d]{1,4},\d{2})/iu',
            '/cena\s+przed\s+obniżką[^:]*[:\s]*([\d]{1,4},\d{2})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $tile, $matches)) {
                return PriceText::normalise($matches[1]);
            }
        }

        return null;
    }

    private function mechanic(string $tile): PromoType
    {
        return match (true) {
            (bool) preg_match('/za\s+grosz|za\s+złotówkę/iu', $tile) => PromoType::SecondForFixed,
            (bool) preg_match('/gratis|\b\d\s*\+\s*\d\b/iu', $tile) => PromoType::OnePlusOne,
            (bool) preg_match('/lidl\s*plus|kupon|aktywuj/iu', $tile) => PromoType::LoyaltyCard,
            (bool) preg_match('/taniej|-\s?\d{1,2}\s?%/iu', $tile) => PromoType::Simple,
            default => PromoType::None,
        };
    }

    /**
     * "przy zakupie 2" states the condition outright; "2+1" states it as a count, and "Trzeci,
     * najtańszy za grosz" names the item's ordinal. A conditional mechanic with no stated count is
     * left null on purpose — that is the shape the validation gate rejects, and saying "unknown" is
     * better than assuming the common case.
     */
    /**
     * Whether the tile prints the condition in words, as opposed to a badge a reader has to
     * interpret. Only the printed phrase outranks a mechanic badge — "2+1 gratis" is itself the
     * complete statement of a different offer, and inferring a purchase condition from it would
     * turn every 1+1 in the leaflet into a conditional unit price.
     */
    private function statesPurchaseCondition(string $tile): bool
    {
        return (bool) preg_match('/przy\\s+zakupie\\s+\\d+/iu', $tile);
    }

    private function requiredQuantity(string $tile): ?int
    {
        if (preg_match('/przy\s+zakupie\s+(\d+)/iu', $tile, $matches)) {
            return PriceText::quantity($matches[1]);
        }

        if (preg_match('/\b(\d)\s*\+\s*(\d)\b/u', $tile, $matches)) {
            return (int) $matches[1] + (int) $matches[2];
        }

        return match (true) {
            (bool) preg_match('/trzeci/iu', $tile) => 3,
            (bool) preg_match('/drugi/iu', $tile) => 2,
            default => null,
        };
    }

    private function fixedSecondPrice(string $tile): ?string
    {
        if (preg_match('/za\s+złotówkę/iu', $tile)) {
            return '1.00';
        }

        return preg_match('/za\s+grosz/iu', $tile) ? '0.01' : null;
    }
}
