<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O número-índice IPCA é de magnitude muito maior que as taxas CDI/prefixada (milhares),
     * e crescente no tempo. A precisão decimal(12,8) (máx. 9.999,99999999) não comporta a série
     * IPCA no longo prazo, então ampliamos para decimal(20,8). Valores CDI existentes não mudam.
     */
    public function up(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->decimal('rate_value', 20, 8)->change();
        });
    }

    public function down(): void
    {
        Schema::table('index_rates', function (Blueprint $table): void {
            $table->decimal('rate_value', 12, 8)->change();
        });
    }
};
