<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('business_calendars')->where('code', 'B3')->update([
            'materialization_policy' => 'legacy',
            'updated_at' => now(),
        ]);

        DB::table('business_calendars')->where('code', 'BR_BANKING_ANBIMA')->update([
            'materialization_policy' => 'weekday_with_official_exceptions',
            'updated_at' => now(),
        ]);

        DB::table('business_calendars')->where('code', 'B3_LISTED_TRADING')->update([
            'materialization_policy' => 'explicit_official_decisions',
            'is_official' => false,
            'financial_use_allowed' => false,
            'available_for_new_configurations' => false,
            'updated_at' => now(),
        ]);

        DB::table('business_calendars')->where('is_homologation', true)->update([
            'materialization_policy' => 'homologation',
            'updated_at' => now(),
        ]);

        DB::table('business_calendars')->updateOrInsert(
            ['code' => 'BR_NATIONAL_HOLIDAYS'],
            [
                'name' => 'Feriados Nacionais — Brasil',
                'purpose' => 'Sábados, domingos e feriados de âmbito nacional instituídos por legislação federal. Não inclui automaticamente feriados bancários, Carnaval, Corpus Christi, feriados locais ou sessões B3.',
                'calendar_type' => 'federal_legal_holidays',
                'source' => 'Legislação federal brasileira (Planalto/Diário Oficial da União)',
                'status' => 'review_required',
                'import_mode' => 'versioned_federal_legislation',
                'materialization_policy' => 'weekday_with_official_exceptions',
                'is_official' => true,
                'financial_use_allowed' => false,
                'is_legacy' => false,
                'is_homologation' => false,
                'accepts_anbima' => false,
                'available_for_new_configurations' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('business_calendars')->where('code', 'BR_NATIONAL_HOLIDAYS')->delete();

        DB::table('business_calendars')->where('code', 'B3_LISTED_TRADING')->update([
            'is_official' => true,
            'financial_use_allowed' => true,
            'updated_at' => now(),
        ]);
    }
};
