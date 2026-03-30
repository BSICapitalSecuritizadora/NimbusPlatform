<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_registration_screen_shows_restricted_message_without_token(): void
    {
        $response = $this->get(route('register'));

        $response->assertSee('Acesso restrito');
        $response->assertDontSee('data-test="register-user-button"', false);
    }

    public function test_registration_screen_shows_form_with_valid_token(): void
    {
        $invitation = Invitation::create([
            'email' => 'invited@example.com',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->get(route('register', ['token' => $invitation->token]));

        $response->assertOk();
        $response->assertSee('Criar conta');
    }

    public function test_new_users_can_register_with_valid_invitation(): void
    {
        $invitation = Invitation::create([
            'email' => 'test@example.com',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'invitation_token' => $invitation->token,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();

        $invitation->refresh();
        $this->assertNotNull($invitation->used_at);
    }

    public function test_registration_fails_without_invitation_token(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertSessionHasErrors('invitation_token');
        $this->assertGuest();
    }

    public function test_registration_fails_with_expired_invitation(): void
    {
        $invitation = Invitation::create([
            'email' => 'expired@example.com',
            'token' => Str::random(64),
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'expired@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'invitation_token' => $invitation->token,
        ]);

        $response->assertSessionHasErrors('invitation_token');
        $this->assertGuest();
    }

    public function test_registration_saves_cargo_and_departamento(): void
    {
        $invitation = Invitation::create([
            'email' => 'professional@example.com',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post(route('register.store'), [
            'name' => 'Ana Silva',
            'email' => 'professional@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'invitation_token' => $invitation->token,
            'cargo' => 'Analista Financeiro',
            'departamento' => 'Comercial',
        ]);

        $this->assertAuthenticated();

        $user = auth()->user();
        $this->assertEquals('Analista Financeiro', $user->cargo);
        $this->assertEquals('Comercial', $user->departamento);
    }
}
