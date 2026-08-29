<?php

namespace App\Basket;

use Illuminate\Contracts\Session\Session;

/**
 * The user's basket while they are building it, kept in the session.
 *
 * Deliberately not a table: persisting a basket to an account is S-03's outcome, and this slice
 * would otherwise design the schema S-03 has to live with.
 *
 * Every read and write of the basket goes through here, so quantity clamping, duplicate merging
 * and forgetting a stale comparison each have exactly one enforcement point. A controller that
 * reached into the session directly could skip any of the three.
 */
final readonly class BasketSession
{
    /**
     * Slug-keyed quantities. A map makes merging a duplicate a single lookup; insertion order —
     * which PHP arrays preserve — is what the report renders in.
     */
    private const LINES_KEY = 'basket.lines';

    private const COMPARED_KEY = 'basket.compared';

    /**
     * Flashed for exactly one request, so the page that replaces a discarded report can say why
     * it went away. Sticky state would keep apologising long after the user stopped caring.
     */
    private const STALE_KEY = 'basket.stale';

    public function __construct(private Session $session) {}

    /**
     * The basket in the shape BasketComparator::compare() expects.
     *
     * @return list<array{product: string, quantity: int}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->map() as $slug => $quantity) {
            $lines[] = ['product' => $slug, 'quantity' => $quantity];
        }

        return $lines;
    }

    public function isEmpty(): bool
    {
        return $this->map() === [];
    }

    /**
     * Add to an existing line rather than creating a second one for the same product.
     */
    public function add(string $slug, int $quantity = 1): void
    {
        $map = $this->map();
        $map[$slug] = $this->clamp(($map[$slug] ?? 0) + $quantity);

        $this->store($map);
    }

    /**
     * Set a line's quantity outright. A quantity below the minimum removes the line instead of
     * storing a zero the report would then have to render as nothing.
     */
    public function setQuantity(string $slug, int $quantity): void
    {
        if ($quantity < $this->min()) {
            $this->remove($slug);

            return;
        }

        $map = $this->map();

        if (! array_key_exists($slug, $map)) {
            return;
        }

        $map[$slug] = $this->clamp($quantity);

        $this->store($map);
    }

    public function remove(string $slug): void
    {
        $map = $this->map();

        unset($map[$slug]);

        $this->store($map);
    }

    public function clear(): void
    {
        $this->store([]);
    }

    /**
     * Whether the user has asked for a comparison of the basket as it currently stands.
     */
    public function wantsComparison(): bool
    {
        return (bool) $this->session->get(self::COMPARED_KEY, false);
    }

    public function markCompared(): void
    {
        $this->session->put(self::COMPARED_KEY, true);
    }

    public function forgetComparison(): void
    {
        $this->session->forget(self::COMPARED_KEY);
    }

    /**
     * Whether the request that led here discarded a report the user was looking at.
     *
     * Read only for the explanatory note: the report itself is already gone, so this cannot
     * resurrect a stale verdict — it just stops the disappearance from looking like a bug.
     */
    public function comparisonWentStale(): bool
    {
        return (bool) $this->session->get(self::STALE_KEY, false);
    }

    /**
     * @return array<string, int>
     */
    private function map(): array
    {
        $stored = $this->session->get(self::LINES_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Every write lands here, so every write also invalidates a comparison the user may be
     * looking at — a verdict shown next to a basket it was not computed from is exactly the
     * wrong-verdict failure the PRD guardrail exists to prevent.
     *
     * @param  array<string, int>  $map
     */
    private function store(array $map): void
    {
        $hadComparison = $this->wantsComparison();

        $this->session->put(self::LINES_KEY, $map);
        $this->forgetComparison();

        // Only when something was actually discarded — otherwise the note would fire on a first
        // visit, claiming a comparison the user never ran.
        if ($hadComparison) {
            $this->session->flash(self::STALE_KEY, true);
        }
    }

    private function clamp(int $quantity): int
    {
        return max($this->min(), min($quantity, $this->max()));
    }

    private function min(): int
    {
        return (int) config('koszykomat.basket.min_quantity');
    }

    private function max(): int
    {
        return (int) config('koszykomat.basket.max_quantity');
    }
}
