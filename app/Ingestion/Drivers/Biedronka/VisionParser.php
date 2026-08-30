<?php

namespace App\Ingestion\Drivers\Biedronka;

use App\Enums\PromoType;
use App\Ingestion\Asset;
use App\Ingestion\Contracts\Parser;
use App\Ingestion\Offer;
use App\Ingestion\PriceText;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Reads Biedronka's leaflet pages with a vision model, because its API returns images and nothing else.
 *
 * This is the only place in the product where a number can be invented rather than read. Classic OCR
 * was tried on this corpus and failed in the worst possible way — it read the promo labels correctly
 * and turned the prices into garbage, producing well-formed rows with wrong numbers. Model confidence
 * does not detect that (log-probability scores barely above chance on comparable extraction
 * benchmarks), so nothing here is trusted on the model's say-so: every offer goes through
 * App\Ingestion\Validation\PriceEntryGate, and a structured output schema makes malformed responses a
 * transport error instead of silent bad data.
 *
 * Bounding boxes are requested per offer so a flagged price resolves to a crop a human can check in
 * seconds rather than a page they have to search.
 */
final readonly class VisionParser implements Parser
{
    /**
     * @return list<string>
     */
    public function accepts(): array
    {
        return [Asset::KIND_IMAGE];
    }

    /**
     * @param  list<Asset>  $assets
     * @return list<Offer>
     */
    public function parse(array $assets): array
    {
        $offers = [];

        foreach ($assets as $asset) {
            if ($asset->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            $offers = array_merge($offers, $this->parsePage($asset));
        }

        return $offers;
    }

    /**
     * @return list<Offer>
     */
    private function parsePage(Asset $asset): array
    {
        try {
            $response = Prism::structured()
                ->using(Provider::Gemini, (string) config('leaflets.vision.model'))
                ->withSchema($this->schema())
                ->withMessages([new UserMessage($this->prompt(), [Image::fromLocalPath($asset->path)])])
                ->asStructured();
        } catch (\Throwable $e) {
            // A failed page must not abort the leaflet: the pages that did parse are still useful,
            // and a missing offer degrades to "brak danych" rather than to a wrong price.
            Log::warning('Biedronka vision page failed', [
                'path' => $asset->path,
                'page' => $asset->pageNumber,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->toOffers($response->structured['offers'] ?? [], $asset);
    }

    /**
     * Maps the model's structured output onto Offer DTOs.
     *
     * Public so the offer-mapping path can be exercised against a captured response without a
     * network call — the durable half of the gold-set fixture.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<Offer>
     */
    public function toOffers(array $rows, Asset $asset): array
    {
        $model = (string) config('leaflets.vision.model');
        $offers = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $promoType = PromoType::tryFrom((string) ($row['promo_type'] ?? 'none')) ?? PromoType::None;

            $offers[] = new Offer(
                networkSlug: 'biedronka',
                rawName: $name,
                // Everything money-shaped goes through the normaliser: the model returns "19,99 zł"
                // and Money::fromDecimalString() throws on that.
                regularPrice: PriceText::normalise($this->text($row, 'regular_price')),
                promoType: $promoType,
                promoPrice: PriceText::normalise($this->text($row, 'promo_price')),
                requiredQuantity: $promoType->isConditional()
                    ? PriceText::quantity($this->text($row, 'required_quantity'))
                    : null,
                secondItemPrice: $promoType->isConditional()
                    ? PriceText::normalise($this->text($row, 'second_item_price'))
                    : null,
                pageNumber: $asset->pageNumber,
                sourceBox: is_array($row['box_2d'] ?? null) ? $row['box_2d'] : null,
                source: 'biedronka.vision.'.$model,
                confidence: $this->confidence($row),
            );
        }

        return $offers;
    }

    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function confidence(array $row): ?string
    {
        $value = $row['confidence'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        return number_format(max(0.0, min(1.0, (float) $value)), 2, '.', '');
    }

    private function schema(): ObjectSchema
    {
        $offer = new ObjectSchema(
            name: 'offer',
            description: 'One priced product offer printed on the page.',
            properties: [
                new StringSchema('name', 'Product name exactly as printed, including brand and size.'),
                new StringSchema('regular_price', 'Undiscounted price as printed, e.g. "5,99". Null if not shown.', nullable: true),
                new EnumSchema('promo_type', 'Which promo mechanic applies.', array_column(PromoType::cases(), 'value')),
                new StringSchema('promo_price', 'Discounted unit price, for simple or loyalty-card promotions only.', nullable: true),
                new StringSchema('required_quantity', 'How many items the promotion requires, for 1+1 or second-for-fixed only.', nullable: true),
                new StringSchema('second_item_price', 'What the further item costs under the condition, e.g. "0,01".', nullable: true),
                new NumberSchema('confidence', 'How confident you are in the prices on this offer, 0 to 1.'),
                new ArraySchema('box_2d', 'Bounding box of this offer on the page, normalised 0-1000, as [ymin, xmin, ymax, xmax].', new NumberSchema('coord', 'Coordinate'), nullable: true),
            ],
            requiredFields: ['name', 'promo_type', 'confidence'],
        );

        return new ObjectSchema(
            name: 'leaflet_page',
            description: 'Every priced offer visible on one leaflet page.',
            properties: [new ArraySchema('offers', 'The offers on this page.', $offer)],
            requiredFields: ['offers'],
        );
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are reading one page of a Polish supermarket leaflet (Biedronka).

        Extract every priced product offer visible on the page. For each offer:

        - `name`: the product name exactly as printed, including brand and size.
        - `regular_price`: the undiscounted price ("cena regularna", "cena poza promocją"),
          as printed. Null when the page does not show one.
        - `promo_type`: one of
            `none`             — a plain price with no promotion,
            `simple`           — a straight discounted unit price,
            `loyalty_card`     — the price requires the loyalty card / app ("z kartą Moja Biedronka"),
            `one_plus_one`     — buy N, get one free ("1+1 gratis", "2+1"),
            `second_for_fixed` — a further item for a fixed token amount ("drugi za grosz", "za złotówkę").
        - `promo_price`: only for `simple` and `loyalty_card`.
        - `required_quantity` and `second_item_price`: only for `one_plus_one` and `second_for_fixed`.
        - `confidence`: how sure you are of the prices on this offer, 0 to 1.
        - `box_2d`: the offer's bounding box, normalised 0-1000, as [ymin, xmin, ymax, xmax].

        Rules that matter more than completeness:

        - Report prices exactly as printed. Never compute, round, or infer a price.
        - If a number is unclear, leave the field null rather than guessing. A missing offer is
          recoverable; a wrong price is not.
        - Do not invent offers. Only report what is actually printed on this page.
        PROMPT;
    }
}
