<?php

namespace Database\Seeders;

use App\Models\FundType;
use Illuminate\Database\Seeder;

class FundTypeSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Crédito',
            'Estruturado',
            'Imobiliário',
        ])->each(fn (string $name) => FundType::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
