<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Banco Alfa',
            'Banco Beta',
            'Banco Gama',
        ])->each(fn (string $name) => Bank::query()->firstOrCreate([
            'name' => $name,
        ], [
            'logo_path' => 'banks/logos/'.str()->slug($name).'.png',
        ]));
    }
}
