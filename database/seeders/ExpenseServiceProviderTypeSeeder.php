<?php

namespace Database\Seeders;

use App\Models\ExpenseServiceProviderType;
use Illuminate\Database\Seeder;

class ExpenseServiceProviderTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ExpenseServiceProviderType::query()->firstOrCreate([
            'name' => 'Sem tipo definido',
        ]);
    }
}
