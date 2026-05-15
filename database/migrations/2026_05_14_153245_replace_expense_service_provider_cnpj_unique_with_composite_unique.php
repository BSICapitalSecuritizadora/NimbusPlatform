<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_service_providers', function (Blueprint $table) {
            $table->dropUnique('expense_service_providers_cnpj_unique');
            $table->unique(
                ['cnpj', 'expense_service_provider_type_id'],
                'expense_srv_provider_cnpj_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('expense_service_providers', function (Blueprint $table) {
            $table->dropUnique('expense_srv_provider_cnpj_type_unique');
            $table->unique('cnpj');
        });
    }
};
