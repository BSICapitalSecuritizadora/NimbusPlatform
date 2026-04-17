<?php

namespace Database\Seeders;

use App\Models\Fund;
use Illuminate\Database\Seeder;

class FundSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FundTypeSeeder::class,
            FundNameSeeder::class,
            FundApplicationSeeder::class,
            BankSeeder::class,
        ]);

        Fund::factory()->count(5)->create();
    }
}
