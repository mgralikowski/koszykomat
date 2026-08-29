@props(['report'])

{{-- The whole answer for one basket: verdict, per-chain totals, per-line evidence and the
     freshness note. Rendered both for the guest's fixed example basket and for a user's own
     basket, so a change to a mechanic label or a validity window cannot drift between the two.

     Renders no <main> and no page heading — the page supplies its own framing. --}}

@php
    $scenarios = $report->cardChangesOutcome()
        ? [$report->withoutCard, $report->withCard]
        : [$report->headline()];
    $showScenarioLabels = count($scenarios) > 1;
@endphp

<section class="mb-8 space-y-4" aria-label="Werdykt">
    @foreach ($scenarios as $scenario)
        @php
            $verdict = $scenario->verdict;
        @endphp

        <div class="rounded-2xl bg-white p-5 ring-1 ring-slate-200 sm:p-6">
            @if ($showScenarioLabels)
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ $scenario->scenario->label() }}
                </p>
            @endif

            @if ($verdict->hasWinner())
                <p class="text-2xl font-semibold sm:text-3xl">
                    Taniej w <span class="text-emerald-700">{{ $verdict->winner->name }}</span>
                </p>
                <p class="mt-1 text-slate-600">
                    Różnica: <span class="font-medium text-slate-900">{{ $verdict->margin->format() }}</span>
                </p>
            @elseif ($verdict->isNoData())
                <p class="text-2xl font-semibold text-slate-700 sm:text-3xl">Brak danych</p>
                <p class="mt-1 text-slate-600">
                    Nie mamy kompletu aktualnych cen, więc nie wskazujemy tańszej sieci.
                    Brakuje: {{ implode(', ', $verdict->missingProducts) }}.
                </p>
            @else
                <p class="text-2xl font-semibold sm:text-3xl">Remis</p>
                <p class="mt-1 text-slate-600">W obu sieciach koszyk kosztuje tyle samo.</p>
            @endif

            <dl class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($scenario->results as $result)
                    <div class="flex items-baseline justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                        <dt class="text-slate-600">{{ $result->network->name }}</dt>
                        <dd class="font-semibold tabular-nums">
                            {{ $result->total?->format() ?? 'brak danych' }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</section>

<section aria-label="Szczegóły koszyka">
    <h2 class="mb-3 text-lg font-semibold">Co się składa na ten wynik</h2>

    <div class="space-y-3">
        @foreach ($report->basketLines as $line)
            <article class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <header class="mb-3 flex items-baseline justify-between gap-3">
                    <h3 class="font-medium">{{ $line->name() }}</h3>
                    <span class="shrink-0 text-sm text-slate-500">{{ $line->quantity }} szt.</span>
                </header>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($report->withoutCard->results as $networkSlug => $result)
                        @php
                            $price = $result->lineFor($line->slug);
                            $cardPrice = $report->withCard->resultFor($networkSlug)?->lineFor($line->slug);
                            $cardDiffers = $price && $cardPrice && ! $cardPrice->total->equals($price->total);
                        @endphp

                        <div class="rounded-lg border border-slate-100 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $result->network->name }}
                            </p>

                            @if ($price)
                                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $price->total->format() }}</p>

                                <p class="mt-1 text-sm text-slate-700">{{ $price->listing->name }}</p>
                                <p class="text-sm text-slate-500">
                                    marka: {{ $price->listing->brand ?? 'brak marki' }}
                                    @if ($price->listing->size_label)
                                        · {{ $price->listing->size_label }}
                                    @endif
                                </p>

                                <p class="mt-2 inline-block rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                    {{ $price->appliedPromo->label() }}
                                </p>

                                @if ($price->promoRequiredMoreItems)
                                    <p class="mt-2 text-xs text-amber-700">
                                        Promocja wymaga min. {{ $price->entry->required_quantity }} szt. —
                                        przy tej ilości nie obowiązuje.
                                    </p>
                                @endif

                                @if ($cardDiffers)
                                    <p class="mt-2 text-xs text-slate-600">
                                        Z kartą: <span class="font-medium">{{ $cardPrice->total->format() }}</span>
                                    </p>
                                @endif

                                <p class="mt-2 text-xs text-slate-500">
                                    Gazetka ważna {{ $price->validFrom->format('d.m.Y') }}–{{ $price->validTo->format('d.m.Y') }}
                                </p>
                            @else
                                <p class="mt-1 text-slate-500">brak danych</p>
                                <p class="text-sm text-slate-500">Nie mamy aktualnej ceny tego produktu w tej sieci.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>

<footer class="mt-8 text-sm text-slate-500">
    <p>
        Ceny pochodzą z aktualnych gazetek — przy każdej widać okres jej ważności.
        Porównanie na dzień {{ $report->comparedOn->format('d.m.Y') }}.
    </p>
    <p class="mt-1">
        Promocje warunkowe naliczamy od faktycznej liczby sztuk: nie dokładamy do koszyka
        niczego, czego nie było na liście.
    </p>
</footer>
