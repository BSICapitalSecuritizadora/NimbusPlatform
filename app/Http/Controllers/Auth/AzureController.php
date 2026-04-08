<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Filament\Notifications\Notification;

class AzureController extends Controller
{
    /**
     * Redirect the user to the Microsoft authentication page.
     */
    public function redirect()
    {
        return Socialite::driver('azure')->redirect();
    }

    /**
     * Obtain the user information from Microsoft.
     */
    public function callback()
    {
        try {
            $azureUser = Socialite::driver('azure')->user();
        } catch (\Exception $e) {
            return redirect('/admin/login')->with('error', 'Falha na autenticação com a Microsoft.');
        }

        // Check if the user exists in our local database by email
        $user = User::where('email', $azureUser->getEmail())->first();

        if (! $user) {
            // No account found - restriction: No auto-creation allowed
            Notification::make()
                ->title('Acesso Negado')
                ->body('Seu e-mail não está cadastrado neste sistema. Entre em contato com o administrador.')
                ->danger()
                ->send();

            return redirect('/admin/login');
        }

        // Update azure_id if not set yet
        if (! $user->azure_id) {
            $user->update(['azure_id' => $azureUser->getId()]);
        }

        // Check if user has permission to access the panel (handled by the model's canAccessPanel)
        // But for Filament, we just log in and let the panel policy handle it.
        Auth::login($user);

        return redirect()->intended('/admin');
    }
}
