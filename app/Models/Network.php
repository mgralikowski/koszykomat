<?php

namespace App\Models;

use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A retail chain (Lidl, Biedronka).
 */
#[Fillable(['slug', 'name'])]
class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory;

    /**
     * @return HasMany<NetworkProduct, $this>
     */
    public function networkProducts(): HasMany
    {
        return $this->hasMany(NetworkProduct::class);
    }

    /**
     * @return HasMany<Leaflet, $this>
     */
    public function leaflets(): HasMany
    {
        return $this->hasMany(Leaflet::class);
    }
}
