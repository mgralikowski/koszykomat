<?php

namespace App\Models;

use Database\Factories\NetworkProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chain's listing of a canonical product, with the brand and size label the comparison
 * report shows so the user can judge whether the pairing is fair.
 */
#[Fillable(['network_id', 'product_id', 'name', 'brand', 'size_label'])]
class NetworkProduct extends Model
{
    /** @use HasFactory<NetworkProductFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<PriceEntry, $this>
     */
    public function priceEntries(): HasMany
    {
        return $this->hasMany(PriceEntry::class);
    }
}
