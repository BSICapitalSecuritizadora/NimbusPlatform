<?php

use App\Models\ProposalSector;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ProposalSector::provisionOfficialSectors();
    }

    /**
     * Reverse the migrations.
     *
     * The official sectors are reference data required by the public proposal
     * form and are referenced by existing proposals, so they are kept in place.
     */
    public function down(): void
    {
        //
    }
};
