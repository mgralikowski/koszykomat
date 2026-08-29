<?php

namespace App\Http\Controllers;

use App\Basket\BasketSession;
use App\Models\Product;
use App\Pricing\BasketComparator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The logged-in user's basket (FR-003): pick products, set quantities, compare.
 *
 * Thin on purpose — every basket rule lives in BasketSession and every number comes from the
 * pricing engine, so there is nothing here to get wrong about either.
 */
class BasketController extends Controller
{
    public function __construct(private readonly BasketSession $basket) {}

    public function show(BasketComparator $comparator): View
    {
        $lines = $this->basket->lines();

        // One query for the names of what is in the basket; the picker's catalogue is a second.
        $products = Product::query()
            ->whereIn('slug', array_column($lines, 'product'))
            ->get()
            ->keyBy('slug');

        return view('basket.index', [
            'lines' => $lines,
            'products' => $products,
            'catalogue' => Product::query()->orderBy('name')->get(),
            // Built only when the user asked for it and there is something to price. Any basket
            // edit clears the flag, so what renders here always describes the basket above it.
            'report' => $this->basket->wantsComparison() && ! $this->basket->isEmpty()
                ? $comparator->compare($lines)
                : null,
            'comparisonWentStale' => $this->basket->comparisonWentStale(),
        ]);
    }

    /**
     * Comparing is an explicit act (US-01: "tworzy koszyk i uruchamia porównanie"), not a side
     * effect of editing — so it gets its own request rather than recomputing on every change.
     */
    public function compare(): RedirectResponse
    {
        if (! $this->basket->isEmpty()) {
            $this->basket->markCompared();
        }

        return redirect()->route('basket.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'exists:products,slug'],
            'quantity' => ['nullable', 'integer', 'min:'.$this->minQuantity(), 'max:'.$this->maxQuantity()],
        ]);

        $this->basket->add($validated['product'], (int) ($validated['quantity'] ?? 1));

        return redirect()->route('basket.index');
    }

    public function update(Request $request, string $product): RedirectResponse
    {
        // Zero is allowed through validation on purpose: it is how the form removes a line.
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.$this->maxQuantity()],
        ]);

        $this->basket->setQuantity($product, (int) $validated['quantity']);

        return redirect()->route('basket.index');
    }

    public function destroy(string $product): RedirectResponse
    {
        $this->basket->remove($product);

        return redirect()->route('basket.index');
    }

    public function clear(): RedirectResponse
    {
        $this->basket->clear();

        return redirect()->route('basket.index');
    }

    private function minQuantity(): int
    {
        return (int) config('koszykomat.basket.min_quantity');
    }

    private function maxQuantity(): int
    {
        return (int) config('koszykomat.basket.max_quantity');
    }
}
