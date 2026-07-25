<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The canonical, chain-neutral comparison unit. Its per-chain listings are what get compared,
 * and the pairing between them is this model's identity.
 */
#[Fillable(['slug', 'name'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return HasMany<NetworkProduct, $this>
     */
    public function networkProducts(): HasMany
    {
        return $this->hasMany(NetworkProduct::class);
    }
}
