<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

// Deploy verification: returns the git SHA stamped into the release by CI.
// CI fails the deploy when this does not match the pushed commit (stale opcache guard).
Route::get('/_version', function () {
    abort_unless(file_exists(base_path('REVISION')), 404);

    return response(trim(file_get_contents(base_path('REVISION'))))
        ->header('Content-Type', 'text/plain');
});
