<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda o resultado da comparação com as garantias já cadastradas.
 *
 * Antes existia apenas `has_conflict`, um booleano que empilhava quatro
 * situações diferentes: o documento pode complementar, confirmar, alterar ou
 * contradizer o cadastro, e só a última é conflito. Com um booleano, a fila de
 * revisão dizia "conflito" para tudo e empurrava o revisor a criar uma segunda
 * garantia onde bastava enriquecer a existente (§18 e §19 do escopo).
 *
 * `has_conflict` continua na tabela, agora derivado do desfecho, porque a
 * listagem e os filtros já existentes o consultam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_guarantees', function (Blueprint $table): void {
            $table->string('reconciliation_outcome')->nullable()->after('has_conflict');
            $table->decimal('match_score', 5, 4)->nullable()->after('reconciliation_outcome');
            $table->string('match_level')->nullable()->after('match_score');
            $table->json('match_evidence')->nullable()->after('match_level');

            $table->index(['emission_id', 'reconciliation_outcome'], 'extracted_guarantee_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_guarantees', function (Blueprint $table): void {
            $table->dropIndex('extracted_guarantee_outcome_index');
            $table->dropColumn(['reconciliation_outcome', 'match_score', 'match_level', 'match_evidence']);
        });
    }
};
