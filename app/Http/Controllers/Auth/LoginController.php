<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login.
 *
 * Hand-written rather than scaffolded by Breeze or laravel/ui, for two
 * reasons: those starter kits ship their own Tailwind stylesheets and layout,
 * which would fight the Bootstrap admin theme this project uses; and the
 * assignment requires being able to explain every line, which is easier when
 * the auth surface is thirty lines we wrote than a few hundred we published.
 *
 * Registration is intentionally absent. The demo account is created by the
 * seeder, and an open sign-up form on a public demo URL is an invitation to
 * spam.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // A single generic message: telling an attacker that the email
            // exists but the password is wrong is free reconnaissance.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Rotates the session id so a session fixed before login is useless.
        $request->session()->regenerate();

        return redirect()->intended(route('forms.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
