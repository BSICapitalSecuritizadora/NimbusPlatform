<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class CustomLogin extends BaseLogin
{
    public function getHeading(): string|Htmlable
    {
        return 'Entrar no sistema';
    }

    public function getView(): string
    {
        return 'filament.pages.auth.login';
    }

    public function authenticate(): ?LoginResponse
    {
        throw ValidationException::withMessages([
            'data.email' => 'O acesso com e-mail e senha está desativado. Use Microsoft 365.',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
