<?php

namespace App\Models;

use App\Enums\PromoType;
use Database\Factories\PriceEntryFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced offer for one chain's product listing, under one promo mechanic.
 *
 * Money arithmetic contract: `regular_price`, `promo_price` and `second_item_price` are
 * DECIMAL(8,2) cast with `decimal:2`, so Eloquent returns *strings* that silently coerce to
 * float in arithmetic. Always compute through App\Pricing\Money, which is the only class
 * permitted to call bc* — `bcmath.scale` is 0, so any bc* call omitting an explicit scale
 * truncates the fractional part silently. Never use raw `+` / `*` on these values.
 *
 * Which promo parameter columns are meaningful for which `promo_type` is defined by
 * App\Enums\PromoType — the matrix lives there because it cannot be expressed in DDL that
 * works on both MySQL 8 and the in-memory SQLite used by tests.
 */
#[Fillable([
    'leaflet_id',
    'network_product_id',
    'regular_price',
    'promo_type',
    'promo_price',
    'required_quantity',
    'second_item_price',
    'source',
    'confidence',
    'needs_review',
    'source_box',
])]
class PriceEntry extends Model
{
    /** @use HasFactory<PriceEntryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promo_type' => PromoType::class,
            'regular_price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'second_item_price' => 'decimal:2',
            'required_quantity' => 'integer',
            'confidence' => 'decimal:2',
            'needs_review' => 'boolean',
            'source_box' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Leaflet, $this>
     */
    public function leaflet(): BelongsTo
    {
        return $this->belongsTo(Leaflet::class);
    }

    /**
     * @return BelongsTo<NetworkProduct, $this>
     */
    public function networkProduct(): BelongsTo
    {
        return $this->belongsTo(NetworkProduct::class);
    }

    /**
     * Entries whose leaflet is valid on the given date (today by default).
     *
     * The counterpart to Leaflet::validOn() — see the note there on why this is the only way a
     * comparison should read prices.
     */
    #[Scope]
    protected function validOn(Builder $query, DateTimeInterface|string|null $date = null): void
    {
        $query->whereHas('leaflet', fn (Builder $leaflet) => $leaflet->validOn($date));
    }

    /**
     * Entries a verdict may be computed from: fresh AND trusted.
     *
     * This is the scope the comparison reads through, and it is deliberately indivisible. Freshness
     * alone is not enough once ingestion writes rows a model produced — a price that failed the
     * validation gate is exactly as unusable as an expired one, and a caller that took validOn()
     * and forgot the trust filter would reintroduce the wrong-verdict failure the PRD guardrail
     * exists to prevent. Composing both here means there is nothing to remember.
     */
    #[Scope]
    protected function usableOn(Builder $query, DateTimeInterface|string|null $date = null): void
    {
        $query->validOn($date)->where('needs_review', false);
    }
}
