<?php

namespace Database\Seeders;

use App\Models\ProposalSector;
use Illuminate\Database\Seeder;

class ProposalSectorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sectors = ['Imobiliário', 'Agronegócio', 'Outros'];

        foreach ($sectors as $name) {
            ProposalSector::firstOrCreate(['name' => $name]);
        }
    }
}
