<?php

use App\Models\Emission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InitialDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * Os seeders de demonstração nunca rodam em produção.
 *
 * Eles criam o super-admin `admin@bsi.local` e o investidor do portal com senha
 * conhecida, uma Emissão fictícia, e ressincronizam as permissões dos papéis --
 * o que revogaria o que foi concedido pela tela de papéis. Um `db:seed --force`
 * por engano no App Service deixaria a produção com um super-admin de senha
 * pública. A recusa vem antes de qualquer escrita.
 */
uses(RefreshDatabase::class);

function demonstrationDataExists(): bool
{
    return User::query()->where('email', 'admin@bsi.local')->exists()
        || User::query()->where('email', 'investidor@demo.local')->exists()
        || Emission::query()->where('name', 'Serie Exemplo - CRI 001')->exists();
}

it('refuses the default seeder in production and writes nothing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => Artisan::call('db:seed', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'O DatabaseSeeder não roda em produção');

    expect(demonstrationDataExists())->toBeFalse();
});

it('refuses the demonstration seeder called by class in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => Artisan::call('db:seed', ['--class' => InitialDemoSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'O InitialDemoSeeder não roda em produção');

    expect(demonstrationDataExists())->toBeFalse();
});

it('still seeds the demonstration data outside production', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'admin@bsi.local')->exists())->toBeTrue()
        ->and(Emission::query()->where('name', 'Serie Exemplo - CRI 001')->exists())->toBeTrue();
});
