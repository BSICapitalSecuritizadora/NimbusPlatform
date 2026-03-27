<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_representatives', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('email')
                ->constrained()
                ->nullOnDelete();
        });

        $representatives = DB::table('proposal_representatives')
            ->select(['id', 'email'])
            ->get();

        foreach ($representatives as $representative) {
            $userId = DB::table('users')
                ->where('email', $representative->email)
                ->value('id');

            if ($userId) {
                DB::table('proposal_representatives')
                    ->where('id', $representative->id)
                    ->update(['user_id' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('proposal_representatives', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
