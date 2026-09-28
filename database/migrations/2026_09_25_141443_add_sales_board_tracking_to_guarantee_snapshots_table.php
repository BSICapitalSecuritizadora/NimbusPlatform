<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amarra a competência de garantias ao Quadro de Vendas que a alimentou.
 *
 * - `sales_board_coverage`: competência do quadro usada por empreendimento
 *   (e se a posição era a do próprio mês, transportada ou ausente) no momento
 *   da apuração. É o que permite saber, depois, se um quadro publicado mudou o
 *   número gravado;
 * - `sales_board_outdated_at`: quando um quadro publicado/registrado depois da
 *   apuração passou a responder pela competência. Nulo = snapshot em dia;
 * - `partial_coverage_*`: a confirmação explícita de quem fechou a competência
 *   com empreendimento sem quadro do mês ou com posição transportada — quem,
 *   quando e quais empreendimentos/meses foram aceitos.
 *
 * Todas nulas: snapshots anteriores (produção está vazia) seguem válidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantee_snapshots', function (Blueprint $table): void {
            $table->json('sales_board_coverage')->nullable()->after('metadata');
            $table->timestamp('sales_board_outdated_at')->nullable()->after('sales_board_coverage');
            $table->json('partial_coverage_confirmation')->nullable()->after('closed_by');
            $table->timestamp('partial_coverage_confirmed_at')->nullable()->after('partial_coverage_confirmation');
            $table->foreignId('partial_coverage_confirmed_by')->nullable()->after('partial_coverage_confirmed_at')
                ->constrained('users', indexName: 'guarantee_snapshots_partial_confirmed_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guarantee_snapshots', function (Blueprint $table): void {
            $table->dropForeign('guarantee_snapshots_partial_confirmed_by_fk');

            $table->dropColumn([
                'sales_board_coverage',
                'sales_board_outdated_at',
                'partial_coverage_confirmation',
                'partial_coverage_confirmed_at',
                'partial_coverage_confirmed_by',
            ]);
        });
    }
};
