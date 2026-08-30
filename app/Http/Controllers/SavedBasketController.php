<?php

namespace App\Http\Controllers;

use App\Basket\BasketSession;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Baskets kept on an account (FR-005): save the one you built, find it again, throw it away.
 *
 * Every lookup here starts from $request->user()->savedBaskets(). That is the whole of the
 * privacy NFR: another account's id is not in the result set, so findOrFail() 404s instead of
 * fetching a row and then asking whether the caller was allowed to see it. A 403 would be worse
 * than useless — it confirms the basket exists, which is itself a leak across accounts.
 */
class SavedBasketController extends Controller
{
    public function __construct(private readonly BasketSession $basket) {}

    public function index(Request $request): View
    {
        // items.product is eager-loaded for the per-basket count and the product preview;
        // touching ->items lazily in the loop would issue a query per basket.
        $baskets = $request->user()
            ->savedBaskets()
            ->with('items.product')
            ->latest()
            ->get();

        return view('saved.index', [
            'baskets' => $baskets,
            // Counted off the loaded collection rather than re-queried: the cap bounds this list,
            // so it is already entirely in memory.
            'atLimit' => $baskets->count() >= $this->maxPerUser(),
            // Loading discards the working basket. With no JavaScript there is no confirm(), so
            // the warning is rendered ahead of the act — and only when there is something to lose.
            'wouldReplace' => ! $this->basket->isEmpty(),
        ]);
    }

    /**
     * Save the basket as it currently stands, under a name.
     *
     * Always an insert — there is no update-in-place, so no request can overwrite a basket the
     * user did not name in this form.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.$this->maxNameLength()],
        ]);

        $lines = $this->basket->lines();

        if ($lines === []) {
            throw ValidationException::withMessages([
                'name' => 'Koszyk jest pusty — nie ma czego zapisać.',
            ]);
        }

        if ($this->savedCount($request) >= $this->maxPerUser()) {
            throw ValidationException::withMessages([
                'name' => 'Masz już maksymalną liczbę zapisanych koszyków ('.$this->maxPerUser().'). Usuń któryś, żeby zapisać nowy.',
            ]);
        }

        // One query for every slug in the basket. A slug with no product left in the catalogue is
        // dropped rather than saved: the schema's foreign key could not hold it, and a saved
        // basket that silently references nothing would re-compare to a wrong total.
        $productIds = Product::query()
            ->whereIn('slug', array_column($lines, 'product'))
            ->pluck('id', 'slug');

        $items = [];

        foreach ($lines as $line) {
            if ($productIds->has($line['product'])) {
                $items[] = [
                    'product_id' => $productIds->get($line['product']),
                    'quantity' => $line['quantity'],
                ];
            }
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'name' => 'Żadnego z produktów w koszyku nie ma już w katalogu — nie ma czego zapisać.',
            ]);
        }

        // A basket that lost half its lines to a mid-insert failure would re-compare to a wrong
        // total while looking perfectly intact, so the two writes are one unit.
        DB::transaction(function () use ($request, $validated, $items): void {
            $basket = $request->user()->savedBaskets()->create([
                'name' => $validated['name'],
            ]);

            $basket->items()->createMany($items);
        });

        return redirect()->route('saved.index');
    }

    /**
     * Load a saved basket into the session, replacing whatever was there (FR-005).
     *
     * Lands the user back on the basket page rather than showing a report here: re-comparing is an
     * explicit act, and the existing "Porównaj" button already prices whatever the session holds
     * against today's data. That is the whole of "revisit after a refresh" — nothing about the
     * saved rows needs to know the prices changed.
     */
    public function load(Request $request, int $savedBasket): RedirectResponse
    {
        $basket = $request->user()
            ->savedBaskets()
            ->with('items.product')
            ->findOrFail($savedBasket);

        $this->basket->replaceWith($basket->toBasketLines());

        return redirect()
            ->route('basket.index')
            ->with('status', 'Wczytano koszyk „'.$basket->name.'". Kliknij „Porównaj", żeby policzyć go na aktualnych cenach.');
    }

    /**
     * Bound by id, not by implicit route-model binding: implicit binding resolves globally and
     * would fetch another account's basket before any scoping ran.
     */
    public function destroy(Request $request, int $savedBasket): RedirectResponse
    {
        $request->user()
            ->savedBaskets()
            ->findOrFail($savedBasket)
            ->delete();

        return redirect()->route('saved.index');
    }

    private function savedCount(Request $request): int
    {
        return $request->user()->savedBaskets()->count();
    }

    private function maxPerUser(): int
    {
        return (int) config('koszykomat.saved_baskets.max_per_user');
    }

    private function maxNameLength(): int
    {
        return (int) config('koszykomat.saved_baskets.max_name_length');
    }
}
