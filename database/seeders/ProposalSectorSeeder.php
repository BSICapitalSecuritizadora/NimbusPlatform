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
        ProposalSector::provisionOfficialSectors();
    }
}
