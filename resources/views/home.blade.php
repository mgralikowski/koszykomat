@extends('layouts.app')

@section('title', 'Koszykomat — gdzie taniej: Lidl czy Biedronka?')

@section('content')
<main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">

    <header class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Koszykomat</h1>
        <p class="mt-2 text-slate-600">
            Przykładowy koszyk policzony w obu sieciach — z naliczonymi promocjami,
            łącznie z tymi, które wymagają zakupu kilku sztuk.
        </p>
    </header>

    <x-comparison-report :report="$report" />

    {{-- The demo above earns the click; this is where it converts. Rendered for guests too —
         the `auth` middleware on the basket turns a guest's click into the Google round-trip
         and brings them back here, so branching on @auth would only hide the product's main
         feature from everyone who does not yet have an account. --}}
    <section class="mt-8 rounded-2xl bg-slate-900 p-5 text-center sm:p-6">
        <p class="text-lg font-semibold text-white">Policz swój własny koszyk</p>
        <p class="mt-1 text-sm text-slate-300">
            Wybierz produkty, które kupujesz, i sprawdź, gdzie wyjdą taniej.
        </p>
        <a href="{{ route('basket.index') }}"
           class="mt-4 inline-block rounded-lg bg-white px-4 py-2 font-medium text-slate-900 hover:bg-slate-100">
            Zbuduj własny koszyk
        </a>
    </section>

</main>
@endsection
