<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BasketController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

// OAuth-only authentication (FR-002). The callback path is mirrored as a literal in
// config/services.php's `google.redirect` — the two must stay in step.
Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

// POST so the CSRF token is required: a logout reachable by GET can be fired by any link or
// prefetch on another site.
Route::post('/logout', LogoutController::class)->name('logout');

// The basket builder (FR-003) and its report (FR-004) are the logged-in half of the product —
// Access Control puts both behind login. The `auth` middleware stores the intended URL, so a
// guest clicking through from the homepage lands back here after Google.
// {product} is a product slug, not an id.
Route::middleware('auth')->prefix('koszyk')->name('basket.')->group(function () {
    Route::get('/', [BasketController::class, 'show'])->name('index');
    Route::post('/pozycje', [BasketController::class, 'store'])->name('store');
    Route::patch('/pozycje/{product}', [BasketController::class, 'update'])->name('update');
    Route::delete('/pozycje/{product}', [BasketController::class, 'destroy'])->name('destroy');
    Route::delete('/', [BasketController::class, 'clear'])->name('clear');
});

// Deploy verification: returns the git SHA stamped into the release by CI.
// CI fails the deploy when this does not match the pushed commit (stale opcache guard).
Route::get('/_version', function () {
    abort_unless(file_exists(base_path('REVISION')), 404);

    return response(trim(file_get_contents(base_path('REVISION'))))
        ->header('Content-Type', 'text/plain');
});
