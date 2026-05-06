<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Se não tem usuário ou não usa Fortify 2FA, continua
        if (! $user || ! method_exists($user, 'hasEnabledTwoFactorAuthentication')) {
            return $next($request);
        }

        if ((bool) $request->session()->get('auth.microsoft_sso', false)) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.edit')->with('warning', 'Você precisa habilitar a Autenticação de Dois Fatores (2FA) para acessar o painel.');
        }

        return $next($request);
    }
}
