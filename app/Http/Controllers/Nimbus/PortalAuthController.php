<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\AccessToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PortalAuthController extends Controller
{
    public function showRequestForm(): View
    {
        return view('nimbus.auth.login');
    }

    public function verifyPin(Request $request): RedirectResponse
    {
        $request->validate([
            'access_code' => ['required', 'string', 'max:32'],
        ]);

        $normalizedCode = $this->normalizeAccessCode((string) $request->input('access_code'));

        $token = AccessToken::query()
            ->where('code_hash', AccessToken::computeHash($normalizedCode))
            ->where('status', 'PENDING')
            ->first();

        if (! $token || ! $token->portalUser || $token->portalUser->status !== 'ACTIVE') {
            return back()->withErrors(['access_code' => 'Código inválido ou expirado.'])->withInput();
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return back()->withErrors(['access_code' => 'Código expirado. Solicite um novo com a administração.'])->withInput();
        }

        $token->update([
            'status' => 'USED',
            'used_at' => now(),
            'used_ip' => $request->ip(),
            'used_user_agent' => $request->userAgent(),
        ]);

        $user = $token->portalUser;
        $user->update([
            'last_login_at' => now(),
            'last_login_method' => 'ACCESS_CODE',
        ]);

        Auth::guard('nimbus')->login($user);

        $request->session()->regenerate();

        return redirect()->route('nimbus.dashboard');
    }

    private function normalizeAccessCode(string $accessCode): string
    {
        $sanitizedCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $accessCode) ?? '');

        return implode('-', str_split($sanitizedCode, 4));
    }

    public function logout(): RedirectResponse
    {
        Auth::guard('nimbus')->logout();

        return redirect()->route('nimbus.auth.request');
    }
}
