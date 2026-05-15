<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('issuer_situation')->nullable();
            $table->string('bsi_code')->nullable()->unique();
            $table->string('settlement_bank')->nullable();
            $table->string('registrar')->nullable();
            $table->string('law_firm')->nullable();
            $table->string('registered_with_cvm')->nullable();
            $table->string('form_type')->nullable();
            $table->longText('corporate_purpose')->nullable();
            $table->longText('subscription_and_integralization_terms')->nullable();
            $table->longText('amortization_payment_schedule')->nullable();
            $table->longText('remuneration_payment_schedule')->nullable();
            $table->longText('use_of_proceeds')->nullable();
            $table->longText('repactuation')->nullable();
            $table->longText('optional_early_redemption')->nullable();
            $table->longText('early_amortization')->nullable();
            $table->longText('remuneration_calculation')->nullable();
            $table->string('guarantee_fund')->nullable();
            $table->string('expense_fund')->nullable();
            $table->string('reserve_fund')->nullable();
            $table->string('works_fund')->nullable();
            $table->longText('property_description')->nullable();
            $table->longText('segregated_estate')->nullable();
            $table->longText('guarantees_description')->nullable();
        });

        DB::table('emissions')->update([
            'offer_type' => 'CVM 160',
        ]);

        DB::table('emissions')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $emission): void {
                $createdAt = filled($emission->created_at)
                    ? Carbon::parse($emission->created_at)
                    : now();

                DB::table('emissions')
                    ->where('id', $emission->id)
                    ->update([
                        'bsi_code' => sprintf('BSI-%s-%04d', $createdAt->format('Y'), $emission->id),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropUnique(['bsi_code']);
            $table->dropColumn([
                'issuer_situation',
                'bsi_code',
                'settlement_bank',
                'registrar',
                'law_firm',
                'registered_with_cvm',
                'form_type',
                'corporate_purpose',
                'subscription_and_integralization_terms',
                'amortization_payment_schedule',
                'remuneration_payment_schedule',
                'use_of_proceeds',
                'repactuation',
                'optional_early_redemption',
                'early_amortization',
                'remuneration_calculation',
                'guarantee_fund',
                'expense_fund',
                'reserve_fund',
                'works_fund',
                'property_description',
                'segregated_estate',
                'guarantees_description',
            ]);
        });
    }
};
