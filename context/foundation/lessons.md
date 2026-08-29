# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Never let related factories each create their own parent

- **Context**: Any `database/factories/*Factory.php` definition.
- **Problem**: PriceEntryFactory resolved `leaflet_id` and `network_product_id` independently; each spawned its own Network, so every default row put one chain's price inside another chain's leaflet — a shape production cannot hold and no composite foreign key can forbid. It surfaced only in review, with four mandatory promo tests about to be written on those rows.
- **Rule**: Never let two related factories each create their own parent; derive the second from the first so a default row is a shape production can actually hold.
- **Applies to**: implement, impl-review

## Use the @php … @endphp block form in Blade

- **Context**: Any `resources/views/**/*.blade.php` on Laravel 11+.
- **Problem**: Removed Blade directives compile to broken PHP rather than failing loudly, and the parse error names a line far from the actual cause.
- **Rule**: Never use `@php($x = ...)` — removed in Laravel 11. Use the `@php … @endphp` block form.
- **Applies to**: implement
