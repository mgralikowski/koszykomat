@extends('layouts.app')

@section('title', 'Zapisane koszyki — Koszykomat')

@section('content')
<main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">

    <header class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Zapisane koszyki</h1>
        <p class="mt-2 text-slate-600">
            Wróć do koszyka, który już zapisałeś, i policz go jeszcze raz —
            na cenach z aktualnych gazetek.
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

    {{-- Loading replaces the working basket. No JavaScript in this app means no confirm(), so the
         warning is shown ahead of the act rather than at it — and only when a basket would
         actually be lost. --}}
    @if ($wouldReplace)
        <p role="status" class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            Masz produkty w koszyku — wczytanie zapisanego koszyka je zastąpi.
        </p>
    @endif

    @if ($atLimit)
        <p role="status" class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            Masz maksymalną liczbę zapisanych koszyków ({{ config('koszykomat.saved_baskets.max_per_user') }}).
            Usuń któryś, żeby zapisać nowy.
        </p>
    @endif

    @if ($baskets->isEmpty())
        <div class="rounded-xl bg-white p-6 text-center ring-1 ring-slate-200">
            <p class="font-medium">Nie masz jeszcze zapisanych koszyków.</p>
            <p class="mt-1 text-sm text-slate-600">
                Zbierz produkty w koszyku i zapisz go pod własną nazwą.
            </p>
            <a href="{{ route('basket.index') }}"
               class="mt-4 inline-block rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700">
                Przejdź do koszyka
            </a>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($baskets as $basket)
                <li class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="font-medium">{{ $basket->name }}</h2>

                            <p class="mt-0.5 text-sm text-slate-500">
                                {{-- A label form, not a plural: the app locale is `en`, whose
                                     pluralizer has two forms, and Polish needs three — trans_choice
                                     here would render "5 produkty". --}}
                                Zapisany {{ $basket->created_at->format('d.m.Y') }} ·
                                produktów: {{ $basket->items->count() }}
                            </p>

                            @if ($basket->items->isNotEmpty())
                                <p class="mt-1 truncate text-sm text-slate-600">
                                    {{ $basket->items->map(fn ($item) => $item->product->name)->implode(', ') }}
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if ($basket->items->isNotEmpty())
                                <form method="POST" action="{{ route('saved.load', $basket->id) }}">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                                        Wczytaj
                                    </button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('saved.destroy', $basket->id) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded-lg px-3 py-1.5 text-sm text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50 hover:text-slate-700">
                                    Usuń
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

</main>
@endsection
