<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'cargo' => ['nullable', 'string', 'max:255'],
            'departamento' => ['nullable', 'string', 'max:255'],
            'invitation_token' => ['required', 'string'],
        ])->validate();

        $invitation = Invitation::findValid($input['invitation_token'] ?? '');

        if (! $invitation) {
            throw ValidationException::withMessages([
                'invitation_token' => ['O convite é inválido, expirou ou já foi utilizado.'],
            ]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'cargo' => $input['cargo'] ?? null,
            'departamento' => $input['departamento'] ?? null,
            'invited_by' => $invitation->invited_by,
        ]);

        $invitation->update(['used_at' => now()]);

        return $user;
    }
}
