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
 * float in arithmetic. The conditional mechanics spread a price across `required_quantity` —
 * exactly where float drift produces an off-by-one-grosz basket total and an untrustworthy
 * verdict. Compute with BCMath (bcadd/bcsub/bcmul, scale 2) or convert to integer grosze
 * first; never use raw `+` / `*` on these values.
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
}
