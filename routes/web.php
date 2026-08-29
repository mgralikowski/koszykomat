<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LogoutController;
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

// Deploy verification: returns the git SHA stamped into the release by CI.
// CI fails the deploy when this does not match the pushed commit (stale opcache guard).
Route::get('/_version', function () {
    abort_unless(file_exists(base_path('REVISION')), 404);

    return response(trim(file_get_contents(base_path('REVISION'))))
        ->header('Content-Type', 'text/plain');
});
