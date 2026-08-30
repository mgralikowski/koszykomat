<?php

namespace App\Ingestion;

/**
 * The boundary between what a leaflet says and what arithmetic can accept.
 *
 * Polish leaflets write money as "19,99", "19,99 zł", "od 19,99" or "4,59/opak."; a vision model
 * returns whatever it read, sometimes with the currency, sometimes with a range. None of those are
 * `is_numeric`, and App\Pricing\Money::fromDecimalString() throws on anything that is not — which
 * would break the never-throw contract App\Pricing\PromoCalculator is built on and turn a
 * mis-parsed row into a 500 instead of a "brak danych".
 *
 * So every parser normalises here, and every failure returns null rather than throwing. A null
 * reaches the validation gate as a missing value and is flagged; it never reaches Money.
 */
final class PriceText
{
    /**
     * A plain decimal string Money can consume, or null when the text carries no single amount.
     */
    public static function normalise(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // Strip the non-breaking spaces leaflets and PDF text layers are full of.
        $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $text);

        // Keep digits, separators and a leading sign; everything else (zł, /opak., *) is noise.
        $cleaned = preg_replace('/[^0-9,.\-]/u', '', $text) ?? '';

        if ($cleaned === '') {
            return null;
        }

        // A range ("4,59-5,99") or a stray second amount is ambiguous — refuse rather than guess
        // which half the shopper pays.
        if (substr_count($cleaned, '-') > 0 && ! str_starts_with($cleaned, '-')) {
            return null;
        }

        $cleaned = str_replace(',', '.', $cleaned);

        // More than one separator left means two amounts ran together, not a thousands group:
        // leaflet prices are small enough that "1.234.56" is a parse error, not 1234.56.
        if (substr_count($cleaned, '.') > 1) {
            return null;
        }

        if (! is_numeric($cleaned)) {
            return null;
        }

        // Money stores at scale 2; a leaflet price with more precision than a grosz is a misread.
        if (str_contains($cleaned, '.') && strlen(substr($cleaned, strpos($cleaned, '.') + 1)) > 2) {
            return null;
        }

        return $cleaned;
    }

    /**
     * The integer a phrase like "przy zakupie 2 opak." declares, or null when there is none.
     */
    public static function quantity(?string $text): ?int
    {
        if ($text === null || ! preg_match('/\d+/', $text, $matches)) {
            return null;
        }

        $quantity = (int) $matches[0];

        // `price_entries.required_quantity` is an unsignedTinyInteger; anything beyond that is a
        // misread page number or a limit, not a purchase condition.
        return $quantity >= 1 && $quantity <= 255 ? $quantity : null;
    }
}
