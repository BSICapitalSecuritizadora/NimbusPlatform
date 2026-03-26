<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('assigned_representative_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('proposal_representatives')
                ->nullOnDelete();
            $table->unsignedInteger('distribution_sequence')->nullable()->after('assigned_representative_id');
            $table->timestamp('distributed_at')->nullable()->after('distribution_sequence');
            $table->timestamp('completed_at')->nullable()->after('distributed_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_representative_id');
            $table->dropColumn(['distribution_sequence', 'distributed_at', 'completed_at']);
        });
    }
};
