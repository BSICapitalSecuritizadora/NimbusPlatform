<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\AccessToken;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class PortalAuthController extends Controller
{
    /**
     * Mostra o formulário inicial onde o usuário informa o e-mail ou documento.
     */
    public function showRequestForm()
    {
        return view('nimbus.auth.request-pin');
    }

    /**
     * Valida o usuário e envia o PIN de 6 dígitos pro e-mail.
     */
    public function requestPin(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string'
        ]);

        $identifier = $request->input('identifier');

        // Busca o usuário pelo e-mail ou documento
        $user = PortalUser::where('status', 'ACTIVE')
            ->where(function($q) use ($identifier) {
                $q->where('email', $identifier)
                  ->orWhere('document_number', preg_replace('/[^0-9]/', '', $identifier));
            })
            ->first();

        if (!$user) {
            return back()->withErrors(['identifier' => 'Usuário não encontrado ou inativo no portal Nimbus.']);
        }

        // Gera Token de 6 dígitos (Ex: 849201)
        $code = random_int(100000, 999999);

        // Revoga os tokens anteriores pendentes
        $user->accessTokens()->where('status', 'PENDING')->update(['status' => 'REVOKED']);

        // Salva novo token (Válido por 30 minutos)
        $user->accessTokens()->create([
            'code' => $code,
            'status' => 'PENDING',
            'expires_at' => now()->addMinutes(30)
        ]);

        // Aqui disparamos o Job / Mailable nativo do Laravel com o PIN para o $user->email
        // Mail::to($user->email)->queue(new \App\Mail\Nimbus\PortalAccessPinCode($user, $code));

        return redirect()->route('nimbus.auth.verify')->with('user_id', $user->id);
    }

    /**
     * Mostra o formulário de validação do PIN.
     */
    public function showVerifyForm()
    {
        if (!session('user_id')) {
            return redirect()->route('nimbus.auth.request');
        }
        return view('nimbus.auth.verify-pin');
    }

    /**
     * Checa se o código bate e faz autenticação (Session / Custom Guard).
     */
    public function verifyPin(Request $request)
    {
        $request->validate(['code' => 'required|numeric|digits:6']);

        $user = PortalUser::find(session('user_id'));
        if (!$user) {
            return redirect()->route('nimbus.auth.request');
        }

        $token = $user->accessTokens()
            ->where('code', $request->input('code'))
            ->where('status', 'PENDING')
            ->where('expires_at', '>', now())
            ->first();

        if (!$token) {
            return back()->withErrors(['code' => 'Código inválido ou expirado. Tente novamente.']);
        }

        // Marca como usado
        $token->update([
            'status' => 'USED',
            'used_at' => now(),
            'used_ip' => $request->ip(),
            'used_user_agent' => $request->userAgent()
        ]);

        // Atualiza último login
        $user->update([
            'last_login_at' => now(),
            'last_login_method' => 'PIN_EMAIL'
        ]);

        // Faz o login no Laravel Auth System usando um guard customizado para isolar acessos
        Auth::guard('nimbus')->login($user);

        return redirect()->route('nimbus.dashboard');
    }

    public function logout()
    {
        Auth::guard('nimbus')->logout();
        return redirect()->route('nimbus.auth.request');
    }
}
