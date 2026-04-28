<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultTypeId = DB::table('expense_service_provider_types')
            ->where('name', 'Sem tipo definido')
            ->value('id');

        if ($defaultTypeId === null) {
            $defaultTypeId = DB::table('expense_service_provider_types')->insertGetId([
                'name' => 'Sem tipo definido',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasColumn('expense_service_providers', 'expense_service_provider_type_id')) {
            Schema::table('expense_service_providers', function (Blueprint $table) use ($defaultTypeId): void {
                $table->foreignId('expense_service_provider_type_id')
                    ->default($defaultTypeId)
                    ->after('name');
            });
        }

        Schema::table('expense_service_providers', function (Blueprint $table): void {
            $table->foreign('expense_service_provider_type_id', 'expense_srv_provider_type_fk')
                ->references('id')
                ->on('expense_service_provider_types')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_service_providers', function (Blueprint $table): void {
            $table->dropForeign('expense_srv_provider_type_fk');
            $table->dropColumn('expense_service_provider_type_id');
        });
    }
};
