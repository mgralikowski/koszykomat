<?php

namespace App\Ingestion\Validation;

use App\Enums\PromoType;
use App\Models\PriceEntry;
use App\Pricing\Money;

/**
 * Decides whether a parsed price may be trusted enough to appear in a verdict.
 *
 * The failure mode this exists to catch is not a crash — it is confident, well-formed, wrong
 * numbers. Prior art on this corpus produced exactly that: nine "products" from three pages, none
 * of them an actual priced offer. Asking a model how sure it is does not detect that (log-probability
 * scores 0.705 ROC AUC on comparable extraction benchmarks, verbalised confidence 0.692, and five
 * self-consistency samples agree with each other when the model is confidently wrong). Deterministic
 * post-extraction validation does, because this domain has real structural invariants.
 *
 * The gate never throws and never writes. It judges, and the caller decides what to persist.
 */
final readonly class PriceEntryGate
{
    /**
     * A price swing wider than this against the same listing's last known regular price is
     * treated as a parse error rather than a real move. Leaflet prices do swing hard on
     * promotions, so the band is deliberately wide — it catches a misread decimal point
     * (17,99 read as 1799), not a genuine discount.
     */
    private const PLAUSIBLE_SWING = 0.60;

    public function inspect(PriceEntryCandidate $candidate): GateVerdict
    {
        $reasons = [];

        $regular = $this->money($candidate->regularPrice);

        if ($regular === null) {
            // Without a regular price nothing else can be judged, and the overbuy cost the report
            // depends on is uncomputable.
            return GateVerdict::flagged(['regular_price is missing or not a numeric amount']);
        }

        $reasons = array_merge(
            $reasons,
            $this->parameterPresenceViolations($candidate),
            $candidate->promoType->valueViolations(
                $regular,
                $this->money($candidate->promoPrice),
                $candidate->requiredQuantity,
                $this->money($candidate->secondItemPrice),
            ),
            $this->plausibilityViolations($candidate, $regular),
        );

        return $reasons === [] ? GateVerdict::trusted() : GateVerdict::flagged($reasons);
    }

    /**
     * The null-ness matrix that has lived in PromoType since F-01 — a mechanic must carry its own
     * parameters and nothing else.
     *
     * @return list<string>
     */
    private function parameterPresenceViolations(PriceEntryCandidate $candidate): array
    {
        $values = [
            'promo_price' => $candidate->promoPrice,
            'required_quantity' => $candidate->requiredQuantity,
            'second_item_price' => $candidate->secondItemPrice,
        ];

        $reasons = [];

        foreach ($candidate->promoType->requiredParameters() as $column) {
            if ($values[$column] === null) {
                $reasons[] = "{$column} is required for {$candidate->promoType->value} but is missing";
            }
        }

        foreach ($candidate->promoType->forbiddenParameters() as $column) {
            if ($values[$column] !== null) {
                $reasons[] = "{$column} must be null for {$candidate->promoType->value}";
            }
        }

        return $reasons;
    }

    /**
     * An independent witness: the same listing's most recent regular price.
     *
     * This is the only check that needs the database, and the only one that can catch a number
     * that is internally consistent but simply wrong.
     *
     * @return list<string>
     */
    private function plausibilityViolations(PriceEntryCandidate $candidate, Money $regular): array
    {
        if ($candidate->networkProductId === null) {
            return [];
        }

        $previous = PriceEntry::query()
            ->where('network_product_id', $candidate->networkProductId)
            ->where('needs_review', false)
            ->latest('id')
            ->value('regular_price');

        if ($previous === null) {
            return [];
        }

        $before = (float) $previous;
        $now = (float) $regular->toDecimalString();

        if ($before <= 0.0) {
            return [];
        }

        $swing = abs($now - $before) / $before;

        return $swing > self::PLAUSIBLE_SWING
            ? [sprintf('regular_price moved %.0f%% against the last known price (%s → %s)', $swing * 100, $previous, $regular->toDecimalString())]
            : [];
    }

    /**
     * Money::fromDecimalString() throws on anything non-numeric, and PromoCalculator is built on a
     * never-throw contract. A parser is supposed to normalise before it gets here; this is the
     * backstop that keeps a stray "19,99 zł" from turning a flagged row into a 500.
     */
    private function money(?string $amount): ?Money
    {
        if ($amount === null || ! is_numeric($amount)) {
            return null;
        }

        return Money::fromDecimalString($amount);
    }
}
