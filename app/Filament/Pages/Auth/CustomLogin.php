<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

class CustomLogin extends BaseLogin
{
    public function getHeading(): string | Htmlable
    {
        return 'Entrar no sistema';
    }

    public function getView(): string
    {
        return 'filament.pages.auth.login';
    }
}
