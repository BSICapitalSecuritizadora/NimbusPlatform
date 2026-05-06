<?php

namespace App\Http\Controllers\Investor\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvestorAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('investor.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = (bool) $request->boolean('remember');

        if (! auth('investor')->attempt($data, $remember)) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha inválidos.',
            ]);
        }

        if (! auth('investor')->user()->is_active) {
            auth('investor')->logout();

            throw ValidationException::withMessages([
                'email' => 'Sua conta de investidor está inativa. Entre em contato com a administração.',
            ]);
        }

        $request->session()->regenerate();

        // Atualiza último login
        $investor = auth('investor')->user();
        $investor->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('investor.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        auth('investor')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('investor.login');
    }
}
