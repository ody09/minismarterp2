<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt([...$request->validated(), 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => __('app.invalid_credentials')]);
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', Auth::user()->auth_version);

        return redirect()->route(Auth::user()->landingRouteName());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
