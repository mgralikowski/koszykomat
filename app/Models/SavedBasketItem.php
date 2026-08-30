<?php

namespace App\Models;

use Database\Factories\SavedBasketItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a saved basket. Points at the canonical product, not at a chain's listing — the
 * basket is chain-neutral until the comparator prices it.
 */
#[Fillable(['product_id', 'quantity'])]
class SavedBasketItem extends Model
{
    /** @use HasFactory<SavedBasketItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SavedBasket, $this>
     */
    public function savedBasket(): BelongsTo
    {
        return $this->belongsTo(SavedBasket::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
