<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the local session. Provider-agnostic on purpose — logging out of Koszykomat is not, and
 * should not be, logging out of Google.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
