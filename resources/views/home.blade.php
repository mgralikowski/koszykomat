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

</main>
@endsection
