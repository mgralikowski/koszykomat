<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production sits behind Cloudflare (proxied) + nginx — trust the proxy
        // chain so Laravel detects the original HTTPS scheme and client IP.
        $middleware->trustProxies(at: '*');

        // Auth is OAuth-only, so there is no `login` route for the framework to fall back on —
        // without this a guest hitting a gated route gets a RouteNotFoundException. Laravel's
        // unauthenticated handler redirects via redirect()->guest(), which stores the requested
        // URL as `url.intended`; GoogleController::callback then returns the user to it.
        $middleware->redirectGuestsTo(fn () => route('auth.google.redirect'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
