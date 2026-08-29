<?php

namespace App\Pricing;

use App\Enums\PromoType;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\Product;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Compares a basket across every chain and returns an honest verdict.
 *
 * Freshness is enforced at load time: entries are fetched through PriceEntry::validOn(), so an
 * expired price has no code path into a total. That makes the "never a stale verdict" guarantee
 * structural rather than something every caller has to remember.
 *
 * Chains are read from the database rather than hardcoded, keeping the engine chain-agnostic as
 * the PRD requires — adding a third chain is a data change, not a code change.
 */
final class BasketComparator
{
    public function __construct(private readonly PromoCalculator $calculator) {}

    /**
     * @param  list<array{product: string, quantity: int}>  $basket  as declared in config('koszykomat.example_basket')
     */
    public function compare(array $basket, DateTimeInterface|string|null $date = null): ComparisonReport
    {
        $on = $date === null ? today() : Carbon::parse($date)->startOfDay();

        $lines = $this->resolveBasketLines($basket, $on);
        $networks = Network::query()->orderBy('id')->get();

        return new ComparisonReport(
            withoutCard: $this->compareScenario(Scenario::WithoutCard, $lines, $networks),
            withCard: $this->compareScenario(Scenario::WithCard, $lines, $networks),
            basketLines: $lines,
            comparedOn: $on,
        );
    }

    /**
     * Load every product in the basket with its listings and their currently-valid entries in a
     * single eager-loaded pass.
     *
     * Both scenarios then run over these in-memory models — touching ->priceEntries lazily inside
     * the pricing loop would issue a query per line per chain, the N+1 the <2 s budget cannot
     * afford.
     *
     * @param  list<array{product: string, quantity: int}>  $basket
     * @return list<BasketLine>
     */
    private function resolveBasketLines(array $basket, Carbon $on): array
    {
        $slugs = array_column($basket, 'product');

        $products = Product::query()
            ->whereIn('slug', $slugs)
            ->with([
                'networkProducts.network',
                'networkProducts.priceEntries' => fn ($query) => $query->validOn($on)->with('leaflet'),
            ])
            ->get()
            ->keyBy('slug');

        return array_map(
            fn (array $item): BasketLine => new BasketLine(
                slug: $item['product'],
                quantity: (int) $item['quantity'],
                product: $products->get($item['product']),
            ),
            $basket,
        );
    }

    /**
     * @param  list<BasketLine>  $lines
     * @param  Collection<int, Network>  $networks
     */
    private function compareScenario(Scenario $scenario, array $lines, Collection $networks): ScenarioComparison
    {
        $results = [];

        foreach ($networks as $network) {
            $priced = [];
            $unpriced = [];

            foreach ($lines as $line) {
                $linePrice = $this->priceLine($line, $network, $scenario);

                if ($linePrice === null) {
                    $unpriced[] = $line->slug;

                    continue;
                }

                $priced[$line->slug] = $linePrice;
            }

            $results[$network->slug] = new NetworkResult(
                network: $network,
                lines: $priced,
                unpricedProducts: $unpriced,
                total: $unpriced === [] ? $this->sum($priced) : null,
            );
        }

        // An empty basket has nothing to compare, and every chain sums to zero — which decide()
        // would otherwise read as an exact tie and announce as a verdict over nothing.
        if ($lines === []) {
            return new ScenarioComparison($scenario, $results, Verdict::noData([]));
        }

        return new ScenarioComparison($scenario, $results, $this->decide($results));
    }

    /**
     * The cheapest reachable price for one line in one chain, or null when there is none.
     *
     * Every valid entry is evaluated at the requested quantity and the cheapest wins, because
     * that is what the shopper would actually pay. On an exact tie the plainer mechanic wins, so
     * the explanation shown to the user is the simplest true one.
     */
    private function priceLine(BasketLine $line, Network $network, Scenario $scenario): ?LinePrice
    {
        $listing = $line->product?->networkProducts
            ->firstWhere('network_id', $network->id);

        if (! $listing instanceof NetworkProduct) {
            return null;
        }

        $best = null;

        foreach ($listing->priceEntries as $entry) {
            if (! $scenario->allows($entry->promo_type)) {
                continue;
            }

            $cost = $this->calculator->cost($entry, $line->quantity);

            if ($cost === null) {
                continue;
            }

            if ($best === null
                || $cost->total->isLessThan($best['cost']->total)
                || ($cost->total->equals($best['cost']->total)
                    && $this->simplicity($cost->appliedPromo) < $this->simplicity($best['cost']->appliedPromo))
            ) {
                $best = ['cost' => $cost, 'entry' => $entry];
            }
        }

        if ($best === null) {
            return null;
        }

        return new LinePrice(
            listing: $listing,
            quantity: $line->quantity,
            entry: $best['entry'],
            appliedPromo: $best['cost']->appliedPromo,
            total: $best['cost']->total,
            validFrom: $best['entry']->leaflet->valid_from,
            validTo: $best['entry']->leaflet->valid_to,
            promoRequiredMoreItems: $best['cost']->promoRequiredMoreItems,
        );
    }

    /**
     * How plainly a mechanic explains itself — lower is plainer. Only used to break exact ties.
     */
    private function simplicity(PromoType $promoType): int
    {
        return match ($promoType) {
            PromoType::None => 0,
            PromoType::Simple => 1,
            PromoType::LoyaltyCard => 2,
            PromoType::OnePlusOne => 3,
            PromoType::SecondForFixed => 4,
        };
    }

    /**
     * @param  array<string, LinePrice>  $lines
     */
    private function sum(array $lines): Money
    {
        return array_reduce(
            $lines,
            fn (Money $carry, LinePrice $line): Money => $carry->plus($line->total),
            Money::zero(),
        );
    }

    /**
     * Apply the whole-basket no-data rule, then name a winner.
     *
     * A gap in ANY chain suppresses the verdict for all of them: two chains that priced different
     * subsets of the basket are not comparable, and a confident number there would be exactly the
     * wrong-verdict failure the guardrail exists to prevent.
     *
     * @param  array<string, NetworkResult>  $results
     */
    private function decide(array $results): Verdict
    {
        if ($results === []) {
            return Verdict::noData([]);
        }

        $missing = [];

        foreach ($results as $result) {
            $missing = array_merge($missing, $result->unpricedProducts);
        }

        if ($missing !== []) {
            return Verdict::noData(array_values(array_unique($missing)));
        }

        $ranked = array_values($results);

        usort($ranked, function (NetworkResult $a, NetworkResult $b): int {
            if ($a->total->isLessThan($b->total)) {
                return -1;
            }

            return $b->total->isLessThan($a->total) ? 1 : 0;
        });

        $cheapest = $ranked[0];
        $runnerUp = $ranked[1] ?? null;

        if ($runnerUp === null) {
            return Verdict::winner($cheapest->network, Money::zero());
        }

        if ($cheapest->total->equals($runnerUp->total)) {
            return Verdict::tie();
        }

        return Verdict::winner($cheapest->network, $runnerUp->total->minus($cheapest->total));
    }
}
