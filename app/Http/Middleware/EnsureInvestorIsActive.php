<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInvestorIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $investor = auth('investor')->user();

        if (! $investor) {
            return $next($request);
        }

        if (! $investor->is_active) {
            auth('investor')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('investor.login')
                ->withErrors(['email' => 'Sua conta de investidor está inativa. Entre em contato com a administração.']);
        }

        return $next($request);
    }
}
