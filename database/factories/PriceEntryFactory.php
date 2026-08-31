<?php

namespace Database\Factories;

use App\Enums\PromoType;
use App\Models\Leaflet;
use App\Models\NetworkProduct;
use App\Models\PriceEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<PriceEntry>
 *
 * Each promo state sets exactly the parameters its mechanic requires and nulls the rest, so a
 * test cannot accidentally build a row that violates the PromoType parameter contract.
 */
class PriceEntryFactory extends Factory
{
    /**
     * Define the model's default state: a plain shelf price, no promo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leaflet_id' => Leaflet::factory(),
            // Inherit the leaflet's chain. Building both children independently gave each its own
            // Network, so a default row put a Lidl price inside a Biedronka leaflet — a shape no
            // production data can have and one the schema cannot forbid.
            'network_product_id' => fn (array $attributes): NetworkProduct => NetworkProduct::factory()->create([
                'network_id' => Leaflet::query()->whereKey($attributes['leaflet_id'])->value('network_id'),
            ]),
            'regular_price' => '9.99',
            'promo_type' => PromoType::None,
            'promo_price' => null,
            'required_quantity' => null,
            'second_item_price' => null,
        ];
    }

    /**
     * Refuse to hand back a row whose leaflet and listing belong to different chains.
     *
     * definition() derives the listing's chain from the leaflet, which protects the default path
     * only. A relationship override — `->for($listing, 'networkProduct')` — replaces that closure
     * and leaves `leaflet_id` pointing at a freshly built Leaflet with a chain of its own, which
     * silently reopens the very shape lessons.md forbids. No composite foreign key guards it today,
     * so the factory guards it here: a fixture that cannot exist in production must fail loudly at
     * the point of creation rather than quietly underpinning a green assertion.
     *
     * Use forListing() to attach a price to an existing listing.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PriceEntry $entry): void {
            $leafletNetwork = $entry->leaflet->network_id;
            $listingNetwork = $entry->networkProduct->network_id;

            if ($leafletNetwork !== $listingNetwork) {
                throw new LogicException(sprintf(
                    'PriceEntry fixture is cross-chain: leaflet #%d belongs to network #%d but listing #%d belongs to network #%d. '
                    .'Use PriceEntry::factory()->forListing($listing) instead of ->for($listing, \'networkProduct\').',
                    $entry->leaflet_id,
                    $leafletNetwork,
                    $entry->network_product_id,
                    $listingNetwork,
                ));
            }
        });
    }

    /**
     * Attach a price to an existing listing, keeping the leaflet in that listing's chain.
     *
     * This is the supported way to pin the listing side. Overriding the relationship directly
     * leaves the leaflet in a chain of its own and trips the configure() guard.
     */
    public function forListing(NetworkProduct $listing): static
    {
        return $this->state(fn (): array => [
            'network_product_id' => $listing->id,
            'leaflet_id' => Leaflet::factory()->for($listing->network),
        ]);
    }

    /**
     * A simple discounted unit price.
     */
    public function simple(string $promoPrice = '7.99'): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::Simple,
            'promo_price' => $promoPrice,
            'required_quantity' => null,
            'second_item_price' => null,
        ]);
    }

    /**
     * Buy one, get the second free — the second item costs 0.00.
     *
     * The threshold is a parameter because real leaflets do not stop at two: Lidl prints
     * "2+1 gratis", which the PDF parser reads as a three-item group. A state hard-coded to 2
     * cannot express the shape most of the leaflet is actually in.
     */
    public function onePlusOne(int $requiredQuantity = 2): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::OnePlusOne,
            'promo_price' => null,
            'required_quantity' => $requiredQuantity,
            'second_item_price' => '0.00',
        ]);
    }

    /**
     * Second item for a fixed amount — a złoty or a grosz.
     *
     * Threshold parameterised for the same reason as onePlusOne(): "Trzeci, najtańszy za grosz"
     * is a three-item group, not a pair.
     */
    public function secondForFixed(string $secondItemPrice = '1.00', int $requiredQuantity = 2): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::SecondForFixed,
            'promo_price' => null,
            'required_quantity' => $requiredQuantity,
            'second_item_price' => $secondItemPrice,
        ]);
    }

    /**
     * Price available only with the chain's loyalty card.
     */
    public function loyaltyCard(string $promoPrice = '6.49'): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::LoyaltyCard,
            'promo_price' => $promoPrice,
            'required_quantity' => null,
            'second_item_price' => null,
        ]);
    }

    /**
     * A per-unit price that only applies from `requiredQuantity` items up — the only mechanic that
     * carries both a promo price and a required quantity.
     */
    public function conditionalUnitPrice(string $promoPrice = '4.00', int $requiredQuantity = 3): static
    {
        return $this->state(fn (): array => [
            'promo_type' => PromoType::ConditionalUnitPrice,
            'promo_price' => $promoPrice,
            'required_quantity' => $requiredQuantity,
            'second_item_price' => null,
        ]);
    }
}
