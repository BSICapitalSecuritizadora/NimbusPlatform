<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valor de referência inicial da unidade e a data em que ele passou a valer.
     *
     * Ambos anuláveis porque a base já tem unidades cadastradas sem valor. `NULL`
     * significa "ainda não informado" e `0.00` significa "vale zero" -- são
     * estados diferentes, e um `default` os confundiria: toda unidade legada
     * passaria a afirmar que vale nada.
     *
     * O par é indivisível no domínio (um valor sem data não é posicionável no
     * tempo), mas a regra vive na aplicação: SQLite não aplica CHECK adicionado
     * a tabela existente do mesmo jeito que o MySQL, e o par nulo/nulo precisa
     * continuar válido para o legado.
     */
    public function up(): void
    {
        Schema::table('construction_units', function (Blueprint $table) {
            $table->decimal('base_value', 15, 2)->nullable()->after('unit');
            $table->date('base_value_reference_date')->nullable()->after('base_value');
        });
    }

    public function down(): void
    {
        Schema::table('construction_units', function (Blueprint $table) {
            $table->dropColumn(['base_value', 'base_value_reference_date']);
        });
    }
};
