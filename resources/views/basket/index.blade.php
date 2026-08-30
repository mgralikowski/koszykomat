@extends('layouts.app')

@section('title', 'Twój koszyk — Koszykomat')

@section('content')
<main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">

    <header class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Twój koszyk</h1>
        <p class="mt-2 text-slate-600">
            Dodaj produkty i sprawdź, w której sieci ten koszyk wyjdzie taniej —
            z naliczonymi promocjami, łącznie z tymi na kilka sztuk.
        </p>
    </header>

    @if ($errors->any())
        <div class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li role="alert">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="mb-8 rounded-2xl bg-white p-5 ring-1 ring-slate-200 sm:p-6" aria-label="Dodaj produkt">
        <h2 class="mb-3 text-lg font-semibold">Dodaj produkt</h2>

        <form method="POST" action="{{ route('basket.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf

            <div class="min-w-0 flex-1">
                <label for="product" class="mb-1 block text-sm text-slate-600">Produkt</label>
                <select name="product" id="product" required
                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-slate-900">
                    @foreach ($catalogue as $product)
                        <option value="{{ $product->slug }}">{{ $product->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:w-28">
                <label for="quantity" class="mb-1 block text-sm text-slate-600">Ilość</label>
                <input type="number" name="quantity" id="quantity"
                       value="{{ old('quantity', 1) }}"
                       min="{{ config('koszykomat.basket.min_quantity') }}"
                       max="{{ config('koszykomat.basket.max_quantity') }}"
                       class="w-full rounded-lg border border-slate-200 px-3 py-2 tabular-nums text-slate-900">
            </div>

            <button type="submit"
                    class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700">
                Dodaj
            </button>
        </form>
    </section>

    <section aria-label="Zawartość koszyka">
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h2 class="text-lg font-semibold">W koszyku</h2>

            @if ($lines !== [])
                <form method="POST" action="{{ route('basket.clear') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm text-slate-500 underline hover:text-slate-700">
                        Wyczyść koszyk
                    </button>
                </form>
            @endif
        </div>

        @if ($lines === [])
            <div class="rounded-xl bg-white p-6 text-center ring-1 ring-slate-200">
                <p class="font-medium">Koszyk jest pusty.</p>
                <p class="mt-1 text-sm text-slate-600">
                    Dodaj powyżej pierwszy produkt, a policzymy go w obu sieciach.
                </p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($lines as $line)
                    @php
                        $product = $products->get($line['product']);
                    @endphp

                    <article class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
                        <h3 class="min-w-0 flex-1 font-medium">{{ $product?->name ?? $line['product'] }}</h3>

                        <div class="flex shrink-0 items-center gap-2">
                            <form method="POST" action="{{ route('basket.update', $line['product']) }}"
                                  class="flex items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <label for="qty-{{ $line['product'] }}" class="sr-only">Ilość</label>
                                <input type="number" name="quantity" id="qty-{{ $line['product'] }}"
                                       value="{{ $line['quantity'] }}"
                                       min="0"
                                       max="{{ config('koszykomat.basket.max_quantity') }}"
                                       class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 tabular-nums text-slate-900">
                                <button type="submit"
                                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                                    Zmień
                                </button>
                            </form>

                            <form method="POST" action="{{ route('basket.destroy', $line['product']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded-lg px-3 py-1.5 text-sm text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50 hover:text-slate-700">
                                    Usuń
                                </button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>

            <form method="POST" action="{{ route('basket.compare') }}" class="mt-4">
                @csrf
                <button type="submit"
                        class="w-full rounded-lg bg-slate-900 px-4 py-3 font-semibold text-white hover:bg-slate-700">
                    Porównaj
                </button>
            </form>

            {{-- Only reachable with something in the basket: saving an empty one is refused in
                 the controller too, but there is no reason to offer it here. --}}
            <form method="POST" action="{{ route('saved.store') }}"
                  class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                @csrf

                <div class="min-w-0 flex-1">
                    <label for="saved-name" class="mb-1 block text-sm text-slate-600">
                        Zapisz ten koszyk pod nazwą
                    </label>
                    <input type="text" name="name" id="saved-name" required
                           value="{{ old('name') }}"
                           maxlength="{{ config('koszykomat.saved_baskets.max_name_length') }}"
                           placeholder="np. Zakupy na tydzień"
                           class="w-full rounded-lg border border-slate-200 px-3 py-2 text-slate-900">
                </div>

                <button type="submit"
                        class="shrink-0 rounded-lg px-4 py-2 font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                    Zapisz
                </button>
            </form>
        @endif
    </section>

    {{-- The report is discarded by every basket edit, so its absence needs explaining once —
         otherwise it reads as the page losing your work rather than refusing to show a verdict
         computed from a basket you have since changed. --}}
    @if ($report === null && $comparisonWentStale)
        <p role="status"
           class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            Koszyk się zmienił — poprzednie porównanie już go nie opisuje. Kliknij „Porównaj", żeby policzyć na nowo.
        </p>
    @endif

    @if ($report)
        <section class="mt-8" aria-label="Wynik porównania">
            <x-comparison-report :report="$report" :removable-missing="true" />
        </section>
    @endif

</main>
@endsection
