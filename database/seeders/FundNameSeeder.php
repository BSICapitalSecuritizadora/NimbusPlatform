<?php

namespace Database\Seeders;

use App\Models\FundName;
use App\Models\FundType;
use Illuminate\Database\Seeder;

class FundNameSeeder extends Seeder
{
    public function run(): void
    {
        $creditType = FundType::query()->firstOrCreate(['name' => 'Crédito']);
        $structuredType = FundType::query()->firstOrCreate(['name' => 'Estruturado']);

        collect([
            ['fund_type_id' => $creditType->id, 'name' => 'Fundo Crédito Alpha'],
            ['fund_type_id' => $creditType->id, 'name' => 'Fundo Crédito Beta'],
            ['fund_type_id' => $structuredType->id, 'name' => 'Fundo Estruturado Prime'],
        ])->each(fn (array $attributes) => FundName::query()->firstOrCreate($attributes));
    }
}
