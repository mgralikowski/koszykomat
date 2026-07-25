<?php

namespace App\Models;

use Database\Factories\LeafletFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dated leaflet for one chain. Every price entry belongs to one, which is what gives each
 * price the visible from–to validity window the data-freshness NFR requires.
 */
#[Fillable(['network_id', 'name', 'valid_from', 'valid_to', 'source_type', 'source_reference'])]
class Leaflet extends Model
{
    /** @use HasFactory<LeafletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return HasMany<PriceEntry, $this>
     */
    public function priceEntries(): HasMany
    {
        return $this->hasMany(PriceEntry::class);
    }

    /**
     * Leaflets whose validity window contains the given date (today by default).
     *
     * This scope and its PriceEntry counterpart are the single chokepoint for the freshness
     * guardrail: a comparison must only ever read entries this returns, so that missing or
     * expired data yields "no data" rather than a confidently wrong verdict. Keep the date
     * injectable — tests pin it to assert both the in-window and expired cases.
     */
    #[Scope]
    protected function validOn(Builder $query, DateTimeInterface|string|null $date = null): void
    {
        $date ??= today();

        $query->whereDate('valid_from', '<=', $date)
            ->whereDate('valid_to', '>=', $date);
    }
}
