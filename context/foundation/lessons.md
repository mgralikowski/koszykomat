# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Never let related factories each create their own parent

- **Context**: Any `database/factories/*Factory.php` definition.
- **Problem**: PriceEntryFactory resolved `leaflet_id` and `network_product_id` independently; each spawned its own Network, so every default row put one chain's price inside another chain's leaflet — a shape production cannot hold. It surfaced only in review, with four mandatory promo tests about to be written on those rows.
- **Rule**: Never let two related factories each create their own parent; derive the second from the first so a default row is a shape production can actually hold.
- **Applies to**: implement, impl-review

- **Amendment (2026-08-31, testing-verdict-correctness)**: deriving one parent from the other fixes the **default path only**, and the rule was broken a second time through the gap. A relationship override — `->for($listing, 'networkProduct')` — replaces the derivation closure and leaves the other parent on its own factory, silently rebuilding the forbidden shape. Two live tests were doing exactly this while the suite stayed green.
  - Cover **every construction path**, not just `create()`: each state, each parameterised variant, and each relationship override.
  - Prefer a guard over a test where one is available. A `configure()->afterCreating()` check that throws on the incoherent shape fires in every test, present and future, and cost less than the assertions it replaces. Keep one test that proves the guard bites, or a deleted guard passes unnoticed.
  - Pinning **both** parents from one network stays legitimate; the rule is "never pin only one side".
- **Correction to the original wording**: "no composite foreign key can forbid" is wrong. One *is* expressible — denormalise `network_id` onto the child and add `unique(id, network_id)` to both parents, then declare composite foreign keys. InnoDB and SQLite both support it. It is a schema-cost decision that was never taken, not an impossibility.

## Use the @php … @endphp block form in Blade

- **Context**: Any `resources/views/**/*.blade.php` on Laravel 11+.
- **Problem**: Removed Blade directives compile to broken PHP rather than failing loudly, and the parse error names a line far from the actual cause.
- **Rule**: Never use `@php($x = ...)` — removed in Laravel 11. Use the `@php … @endphp` block form.
- **Applies to**: implement
