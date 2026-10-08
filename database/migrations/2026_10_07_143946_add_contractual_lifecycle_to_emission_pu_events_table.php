<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo de vida do evento contratual de PU (Fase 4).
     *
     * - `status`: `active` ou `cancelled`. Cancelar preserva a evidência; o evento
     *   cancelado sai do cálculo, mas continua no banco com quem, quando e por quê.
     * - `effective_until`: fim da vigência dos eventos com duração (carência,
     *   waiver...). Nulo nos instantâneos -- pagamento de juros e amortização.
     * - `financial_effect`: efeito financeiro explícito dos eventos que não são
     *   pagamento (ex.: `{"spread_rate": "7.50000000"}` na alteração de spread). A
     *   engine age sobre ele, nunca sobre o rótulo do evento.
     * - `document_reference`: documento/cláusula que origina o evento.
     * - `governed_at`: primeira vez em que o evento entrou no retrato de insumos de
     *   uma versão de curva. Daí em diante ele não pode mais ser apagado, só
     *   cancelado.
     * - `active_identity`: coluna gerada (1 no ativo, nula no cancelado) que entra
     *   na unique de identidade. O cancelado deixa de ocupar a identidade
     *   (tipo, data efetiva, sequência), e o evento substituto pode ser cadastrado.
     *   Virtual, e não armazenada, porque o SQLite não aceita acrescentar coluna
     *   armazenada por ALTER TABLE.
     *
     * Colunas novas com padrão seguro: todo evento existente nasce `active`, sem
     * vigência, sem efeito extra e sem governança registrada. Nenhum dado é
     * reescrito.
     */
    public function up(): void
    {
        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('event_type');
            $table->date('effective_until')->nullable()->after('effective_date');
            $table->json('financial_effect')->nullable()->after('amortization_value');
            $table->string('document_reference')->nullable()->after('description');
            $table->timestamp('governed_at')->nullable()->after('document_reference');
            $table->timestamp('cancelled_at')->nullable()->after('governed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->unsignedTinyInteger('active_identity')
                ->nullable()
                ->virtualAs("case when status = 'active' then 1 else null end");
        });

        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->unique(
                ['emission_id', 'event_type', 'effective_date', 'sequence', 'active_identity'],
                'emission_pu_events_active_identity_unique',
            );
        });

        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->dropUnique('emission_pu_events_unique');
        });
    }

    /**
     * Volta a unique antiga, que não distingue cancelado de ativo: se já houver um
     * evento cancelado e o seu substituto na mesma identidade, o rollback falha de
     * propósito em vez de apagar evidência.
     */
    public function down(): void
    {
        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->unique(['emission_id', 'event_type', 'effective_date', 'sequence'], 'emission_pu_events_unique');
        });

        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->dropUnique('emission_pu_events_active_identity_unique');
        });

        Schema::table('emission_pu_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'active_identity',
                'status',
                'effective_until',
                'financial_effect',
                'document_reference',
                'governed_at',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
