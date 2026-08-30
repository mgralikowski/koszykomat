<?php

namespace App\Models;

use Database\Factories\SavedBasketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A basket a user kept on their account (FR-005).
 *
 * Only `name` is fillable. Ownership is never mass-assigned: a saved basket is created through
 * $user->savedBaskets()->create(...), so the owner comes from the relation the request was scoped
 * to rather than from anything the request body could carry.
 */
#[Fillable(['name'])]
class SavedBasket extends Model
{
    /** @use HasFactory<SavedBasketFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SavedBasketItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SavedBasketItem::class);
    }

    /**
     * The basket in the shape BasketComparator::compare() and BasketSession both speak.
     *
     * Living here rather than in a controller keeps one mapping between stored rows and the
     * `list<array{product: string, quantity: int}>` the rest of the app passes around — the load
     * path and any future caller cannot disagree about it.
     *
     * Callers should eager-load `items.product`; a product cascade-deleted out of the catalogue
     * leaves no item row behind, so every item here has a product.
     *
     * @return list<array{product: string, quantity: int}>
     */
    public function toBasketLines(): array
    {
        return $this->items
            ->map(fn (SavedBasketItem $item): array => [
                'product' => $item->product->slug,
                'quantity' => $item->quantity,
            ])
            ->values()
            ->all();
    }
}
