<?php

namespace Tests\Feature\Ingestion;

use App\Enums\PromoType;
use App\Ingestion\Drivers\Lidl\PdfTextParser;
use App\Ingestion\Offer;
use Tests\TestCase;

/**
 * The Lidl parser against real leaflet text, offline and without a 32 MB download.
 *
 * The fixture holds tiles exactly as the parser rebuilds them from the PDF's positioned text, so
 * this pins two things that matter and are easy to lose: what the parser reads (Lidl's own labels)
 * and, just as importantly, what it refuses to invent. Prior iterations produced numbers that
 * looked entirely plausible and belonged to the neighbouring product — the assertions below are
 * written so that behaviour cannot come back unnoticed.
 */
class PdfTextParserTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function tiles(): array
    {
        $raw = file_get_contents(base_path('tests/Fixtures/Ingestion/lidl-tiles.txt'));

        $blocks = array_map('trim', explode("\n---\n", $raw));

        // Drop the leading comment block.
        array_shift($blocks);

        return array_values(array_filter($blocks));
    }

    /**
     * @return list<Offer>
     */
    private function parse(): array
    {
        $parser = app(PdfTextParser::class);

        $method = new \ReflectionMethod($parser, 'offerFromTile');

        $offers = [];

        foreach ($this->tiles() as $index => $tile) {
            $offer = $method->invoke($parser, $tile, $index + 1);

            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    private function offerFor(string $slugFragment): ?Offer
    {
        foreach ($this->parse() as $offer) {
            if (str_contains(mb_strtolower($offer->rawName), mb_strtolower($slugFragment))) {
                return $offer;
            }
        }

        return null;
    }

    public function test_it_reads_the_labelled_regular_price_and_the_purchase_condition(): void
    {
        $offer = $this->offerFor('Masło z Polskiej');

        $this->assertNotNull($offer);
        // "Cena przed obniżką/cena poza promocją: 4,99/opak." — the number Lidl itself labels.
        $this->assertSame('4.99', $offer->regularPrice);
        // "przy zakupie 3" — the condition, also labelled.
        $this->assertSame(3, $offer->requiredQuantity);
        $this->assertSame(PromoType::ConditionalUnitPrice, $offer->promoType);
    }

    public function test_it_never_invents_a_promotional_price(): void
    {
        // The leaflet does not label the promo price anywhere; it exists only as large type in a
        // different tile. Guessing "the cheapest nearby amount" is what previously attached one
        // coffee's price to another, so every offer must come back without one.
        foreach ($this->parse() as $offer) {
            $this->assertNull(
                $offer->promoPrice,
                "Offer [{$offer->rawName}] invented a promotional price the leaflet never labelled."
            );
        }
    }

    public function test_it_does_not_read_the_omnibus_price_as_the_regular_price(): void
    {
        $offer = $this->offerFor('Mleko UHT');

        $this->assertNotNull($offer);
        // The tile carries both "Cena poza promocją: 3,30" and the legally mandated
        // "Najniższa cena z 30 dni przed obniżką: 1,55". They are different numbers about
        // different periods, and reading the second would understate every discount.
        $this->assertSame('3.30', $offer->regularPrice);
        $this->assertSame(6, $offer->requiredQuantity);
    }

    public function test_it_does_not_read_a_unit_price_as_a_price(): void
    {
        $offer = $this->offerFor('Masło z Polskiej');

        // "100 g = 2,50" is a real number about this product and is not what anyone pays.
        $this->assertNotNull($offer);
        $this->assertNotSame('2.50', $offer->regularPrice);
    }

    public function test_it_keeps_gratis_and_za_grosz_as_their_own_mechanics(): void
    {
        $chocolate = $this->offerFor('Czekolada mleczna');
        $coffee = $this->offerFor('kawa ziarnista');

        $this->assertNotNull($chocolate);
        $this->assertSame(PromoType::OnePlusOne, $chocolate->promoType);
        // "2+1 gratis" means three items are involved, not two.
        $this->assertSame(3, $chocolate->requiredQuantity);

        $this->assertNotNull($coffee);
        // "Trzeci, najtańszy za grosz" is genuinely second-for-fixed, not a conditional unit price.
        $this->assertSame(PromoType::SecondForFixed, $coffee->promoType);
        $this->assertSame('0.01', $coffee->secondItemPrice);
    }

    public function test_a_tile_without_a_labelled_price_yields_no_offer(): void
    {
        // The Żelki tile carries a price (4,99) but no label saying what that price is, and it is
        // not in the pairing map either. Both reasons independently mean "no offer" — a missing
        // offer degrades to "brak danych", which is recoverable; a guessed one is not.
        foreach ($this->parse() as $offer) {
            $this->assertStringNotContainsStringIgnoringCase('żelki', $offer->rawName);
        }
    }

    public function test_every_offer_carries_deterministic_provenance(): void
    {
        $offers = $this->parse();

        $this->assertNotEmpty($offers);

        foreach ($offers as $offer) {
            $this->assertSame('lidl.pdf_text', $offer->source);
            // PDF text extraction is exact by construction — no model, no hallucination surface.
            $this->assertSame('1.00', $offer->confidence);
        }
    }
}
