<?php

namespace App\Http\Controllers\Investor\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvestorAuthController extends Controller
{
    public function showLogin()
    {
        return view('investor.auth.login');
    }

    public function login(Request $request)
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

        $request->session()->regenerate();

        // Atualiza último login
        $investor = auth('investor')->user();
        $investor->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('investor.dashboard');
    }

    public function logout(Request $request)
    {
        auth('investor')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('investor.login');
    }
}
