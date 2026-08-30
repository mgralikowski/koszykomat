<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', config('app.name'))</title>

    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-slate-50 text-slate-900 antialiased">

    {{-- Site chrome. The basket link lands here when S-02 arrives, so it is built as a bar, not
         as a one-off login strip. --}}
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
            <a href="/" class="shrink-0 font-semibold tracking-tight">Koszykomat</a>

            @auth
                <div class="flex min-w-0 items-center gap-3">
                    <nav class="flex shrink-0 items-center gap-3 text-sm">
                        <a href="{{ route('basket.index') }}" class="text-slate-700 hover:text-slate-900">Koszyk</a>
                        <a href="{{ route('saved.index') }}" class="text-slate-700 hover:text-slate-900">Zapisane</a>
                    </nav>

                    <span class="hidden truncate text-sm text-slate-600 sm:block">{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                        @csrf
                        <button type="submit"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                            Wyloguj
                        </button>
                    </form>
                </div>
            @else
                <a href="{{ route('auth.google.redirect') }}"
                   class="shrink-0 rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                    Zaloguj się
                </a>
            @endauth
        </div>
    </header>

    {{-- Login failures the callback could not resolve: a declined consent screen, an expired
         OAuth state, or an email already claimed by another identity. --}}
    @if (session('auth_error'))
        <div class="mx-auto max-w-3xl px-4 pt-4">
            <p role="alert"
               class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                {{ session('auth_error') }}
            </p>
        </div>
    @endif

    {{-- Confirmations of something that just happened elsewhere and redirected here, e.g. a saved
         basket loaded into the session. --}}
    @if (session('status'))
        <div class="mx-auto max-w-3xl px-4 pt-4">
            <p role="status"
               class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200">
                {{ session('status') }}
            </p>
        </div>
    @endif

    @yield('content')
</body>
</html>
