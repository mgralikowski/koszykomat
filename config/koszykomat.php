<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Example basket
    |--------------------------------------------------------------------------
    |
    | The fixed basket a guest sees compared on the homepage (FR-001). Entries
    | reference canonical products by slug, so this list is the single source of
    | truth for "which products, how many": the seeder builds its data from these
    | slugs, and the comparison reads the same list rather than hardcoding them.
    |
    | Quantities matter. The conditional mechanics (1+1 gratis, drugi za grosz)
    | only apply from two items up, so a basket where every quantity is 1 would
    | never exercise them.
    |
    */

    'example_basket' => [
        ['product' => 'mleko-32-1l', 'quantity' => 2],
        ['product' => 'maslo-extra-200g', 'quantity' => 1],
        ['product' => 'kawa-ziarnista-1kg', 'quantity' => 1],
        ['product' => 'czekolada-mleczna-100g', 'quantity' => 4],
    ],

    /*
    |--------------------------------------------------------------------------
    | User basket
    |--------------------------------------------------------------------------
    |
    | Bounds for a quantity the user can put on one basket line. The cap is not
    | cosmetic: PromoCalculator multiplies through Money::times($quantity) with
    | no limit of its own, so a quantity smuggled past the form would produce an
    | absurd but entirely plausible-looking total. BasketSession clamps on every
    | write, which is why this is the only place the numbers live.
    |
    */

    'basket' => [
        'min_quantity' => 1,
        'max_quantity' => 99,
    ],

];
