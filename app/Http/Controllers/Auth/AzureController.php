<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AzureController extends Controller
{
    /**
     * Redirect the user to the Microsoft authentication page.
     */
    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver('azure')
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->redirect();
    }

    /**
     * Obtain the user information from Microsoft.
     */
    public function callback(): \Illuminate\Http\RedirectResponse
    {
        try {
            $azureUser = Socialite::driver('azure')->user();
        } catch (Throwable $e) {
            Log::warning('Azure SSO: falha ao obter usuário do Socialite.', ['error' => $e->getMessage()]);

            return redirect('/admin/login')->with('loginError', 'Falha na autenticação com a Microsoft. Tente novamente.');
        }

        $email = $this->resolveMicrosoftEmail($azureUser);

        if (! $email) {
            Log::warning('Azure SSO: e-mail não identificado.', ['azure_id' => $azureUser->getId()]);

            return redirect('/admin/login')->with('loginError', 'Não foi possível identificar o e-mail retornado pela Microsoft.');
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user) {
            Log::warning('Azure SSO: e-mail não cadastrado.', ['email' => $email]);

            return redirect('/admin/login')->with('loginError', 'Seu e-mail ('.$email.') não está cadastrado no sistema. Entre em contato com o administrador.');
        }

        if (! $user->isActive()) {
            Log::warning('Azure SSO: usuário inativo.', ['user_id' => $user->id, 'email' => $email]);

            return redirect('/admin/login')->with('loginError', 'Seu usuário está inativo. Entre em contato com um Super Admin.');
        }

        if (! $user->canAccessPanel(Filament::getPanel('admin'))) {
            Log::warning('Azure SSO: usuário sem permissão de acesso ao painel.', ['user_id' => $user->id, 'email' => $email]);

            return redirect('/admin/login')->with('loginError', 'Seu usuário não possui permissão para acessar o painel administrativo.');
        }

        $user->forceFill([
            'azure_id' => $user->azure_id ?: $azureUser->getId(),
            'email_verified_at' => $user->email_verified_at ?: now(),
            'last_login_at' => now(),
        ])->save();

        Auth::login($user);

        request()->session()->regenerate();

        // Only bypass native 2FA enforcement when Azure itself confirmed MFA (amr claim contains 'mfa' or 'ngcmfa').
        $amr = (array) data_get($azureUser->user, 'amr', []);
        $azureMfaPerformed = ! empty(array_intersect($amr, ['mfa', 'ngcmfa', 'hwk', 'swk']));
        request()->session()->put('auth.microsoft_sso', $azureMfaPerformed);

        return redirect()->intended('/admin');
    }

    private function resolveMicrosoftEmail(\Laravel\Socialite\Contracts\User $azureUser): ?string
    {
        $email = $azureUser->getEmail()
            ?: data_get($azureUser->user, 'mail')
            ?: data_get($azureUser->user, 'userPrincipalName')
            ?: data_get($azureUser->user, 'preferred_username');

        return filled($email) ? Str::lower((string) $email) : null;
    }
}
