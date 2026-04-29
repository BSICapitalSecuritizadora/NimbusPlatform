<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_settings_route_is_disabled_for_sso_only_access(): void
    {
        $this->assertFalse(Route::has('user-password.edit'));
    }
}
