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
        Schema::create('business_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('purpose');
            $table->string('calendar_type', 50);
            $table->string('source', 120)->nullable();
            $table->string('status', 50);
            $table->string('import_mode', 50)->nullable();
            $table->boolean('is_official')->default(false);
            $table->boolean('financial_use_allowed')->default(false);
            $table->boolean('is_legacy')->default(false);
            $table->boolean('is_homologation')->default(false);
            $table->boolean('accepts_anbima')->default(false);
            $table->boolean('available_for_new_configurations')->default(false);
            $table->timestamps();

            $table->index(
                ['available_for_new_configurations', 'financial_use_allowed', 'is_homologation'],
                'business_calendars_selection_index',
            );
        });

        DB::table('business_calendars')->insert([
            [
                'code' => 'B3',
                'name' => 'Legado (dados históricos ANBIMA; não utilizar em novas configurações)',
                'purpose' => 'Compatibilidade retroativa. Continua consultando exclusivamente os registros históricos gravados com o código B3, sem redirecionamento.',
                'calendar_type' => 'legacy_compatibility',
                'source' => 'ANBIMA (carga histórica legada)',
                'status' => 'legacy',
                'import_mode' => 'legacy_only',
                'is_official' => false,
                'financial_use_allowed' => true,
                'is_legacy' => true,
                'is_homologation' => false,
                'accepts_anbima' => true,
                'available_for_new_configurations' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR_BANKING_ANBIMA',
                'name' => 'Calendário bancário ANBIMA',
                'purpose' => 'Dias úteis bancários conforme a publicação oficial de feriados da ANBIMA.',
                'calendar_type' => 'banking',
                'source' => 'ANBIMA',
                'status' => 'active',
                'import_mode' => 'official_spreadsheet',
                'is_official' => true,
                'financial_use_allowed' => true,
                'is_legacy' => false,
                'is_homologation' => false,
                'accepts_anbima' => true,
                'available_for_new_configurations' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'B3_LISTED_TRADING',
                'name' => 'Sessões de negociação B3',
                'purpose' => 'Dias em que há sessão de negociação no mercado listado da B3. Não representa calendário bancário ANBIMA.',
                'calendar_type' => 'listed_trading',
                'source' => 'B3 (fonte oficial ainda pendente de staging e aprovação)',
                'status' => 'awaiting_official_source',
                'import_mode' => 'manual_approval',
                'is_official' => true,
                'financial_use_allowed' => true,
                'is_legacy' => false,
                'is_homologation' => false,
                'accepts_anbima' => false,
                'available_for_new_configurations' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendars');
    }
};
