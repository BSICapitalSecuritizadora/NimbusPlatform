<?php

namespace Database\Seeders;

use App\Models\FundApplication;
use Illuminate\Database\Seeder;

class FundApplicationSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Aplicação principal',
            'Conta reserva',
            'Aplicação transitória',
        ])->each(fn (string $name) => FundApplication::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
