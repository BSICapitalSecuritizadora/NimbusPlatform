<?php

use App\Filament\Pages\Auth\CustomLogin;
use Filament\Auth\Pages\Login;

it('extends the Filament 5 login page', function () {
    expect(is_subclass_of(CustomLogin::class, Login::class))->toBeTrue();
});
