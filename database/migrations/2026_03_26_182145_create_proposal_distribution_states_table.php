<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_distribution_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('last_representative_id')
                ->nullable()
                ->constrained('proposal_representatives')
                ->nullOnDelete();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        DB::table('proposal_distribution_states')->insert([
            'id' => 1,
            'last_representative_id' => null,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_distribution_states');
    }
};
