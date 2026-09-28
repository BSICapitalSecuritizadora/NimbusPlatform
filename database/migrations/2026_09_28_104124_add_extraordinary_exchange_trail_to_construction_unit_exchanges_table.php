<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem encerrou a permuta, quando e por quê.
     *
     * `ended_on` diz a partir de que dia a permuta deixa de valer, que é o que
     * a derivação lê. O encerramento com a operação em curso é decisão da
     * Gestão e precisa de autor e motivo próprios: o motivo da criação explica
     * por que a unidade foi permutada, não por que deixou de ser.
     */
    public function up(): void
    {
        Schema::table('construction_unit_exchanges', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('ended_on');

            $table->foreignId('ended_by_id')->nullable()->after('ended_at')
                ->constrained('users', indexName: 'construction_unit_exchanges_ended_by_foreign')
                ->nullOnDelete();

            $table->text('end_reason')->nullable()->after('ended_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('construction_unit_exchanges', function (Blueprint $table) {
            $table->dropForeign('construction_unit_exchanges_ended_by_foreign');
            $table->dropColumn(['ended_at', 'ended_by_id', 'end_reason']);
        });
    }
};
