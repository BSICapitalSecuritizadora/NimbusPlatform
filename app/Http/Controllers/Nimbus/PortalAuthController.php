<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\AccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PortalAuthController extends Controller
{
    /**
     * Mostra o formulário inicial onde o usuário informa o e-mail ou documento.
     */
    /**
     * Mostra o formulário de login (único passo com o código XXXX-XXXX-XXXX).
     */
    public function showRequestForm()
    {
        return view('nimbus.auth.login');
    }

    /**
     * Faz a verificação e o login direto pela submissão do código.
     */
    public function verifyPin(Request $request)
    {
        $request->validate(['access_code' => 'required|string']);

        $normalizedCode = $this->normalizeAccessCode((string) $request->input('access_code'));
        $rawCode = str_replace('-', '', $normalizedCode);

        $token = AccessToken::whereIn('code', [$normalizedCode, $rawCode])
            ->first();

        if (! $token || ! $token->portalUser || $token->portalUser->status !== 'ACTIVE') {
            return back()->withErrors(['access_code' => 'Código inválido ou expirado.'])->withInput();
        }

        // Verifica se ainda é válido (Você pode alterar a regra de PENDING depois conforme o original)
        if ($token->status === 'REVOKED' || ($token->expires_at && $token->expires_at < now())) {
            return back()->withErrors(['access_code' => 'Código expirado. Solicite um novo com a administração.'])->withInput();
        }

        // Marca como usado
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

        return redirect()->route('nimbus.dashboard');
    }

    private function normalizeAccessCode(string $accessCode): string
    {
        $sanitizedCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $accessCode) ?? '');

        return implode('-', str_split($sanitizedCode, 4));
    }

    public function logout()
    {
        Auth::guard('nimbus')->logout();

        return redirect()->route('nimbus.auth.request');
    }
}
