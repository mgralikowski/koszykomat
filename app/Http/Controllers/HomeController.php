<?php

namespace App\Http\Controllers;

use App\Pricing\BasketComparator;
use Illuminate\View\View;

/**
 * The guest homepage: a fixed example basket compared across every chain (FR-001).
 *
 * Thin by design — the basket comes from configuration and every number comes from the pricing
 * engine, so there is nothing here to get wrong about money.
 */
class HomeController extends Controller
{
    public function __invoke(BasketComparator $comparator): View
    {
        return view('home', [
            'report' => $comparator->compare(config('koszykomat.example_basket', [])),
        ]);
    }
}
