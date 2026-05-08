<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receivables') && (! Schema::hasTable('receivables_legacy_details'))) {
            Schema::rename('receivables', 'receivables_legacy_details');
        }

        if (Schema::hasTable('receivables')) {
            return;
        }

        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id');
            $table->date('reference_month');
            $table->string('portfolio_id');
            $table->unsignedInteger('active_contracts_count');

            $table->decimal('expected_interest_amount', 18, 2);
            $table->decimal('expected_amortization_amount', 18, 2);
            $table->decimal('received_installment_interest_amount', 18, 2);
            $table->decimal('received_installment_amortization_amount', 18, 2);
            $table->decimal('received_prepayment_interest_amount', 18, 2);
            $table->decimal('received_prepayment_amortization_amount', 18, 2);
            $table->decimal('received_default_interest_amount', 18, 2);
            $table->decimal('received_default_amortization_amount', 18, 2);
            $table->decimal('received_interest_and_penalty_amount', 18, 2);

            $table->decimal('performing_balance_pre_event_amount', 18, 2);
            $table->decimal('non_performing_balance_pre_event_amount', 18, 2);
            $table->decimal('performing_balance_post_event_amount', 18, 2);
            $table->decimal('non_performing_balance_post_event_amount', 18, 2);
            $table->decimal('monthly_default_balance_amount', 18, 2);
            $table->decimal('total_default_balance_amount', 18, 2);
            $table->decimal('linked_credits_current_amount', 18, 2);

            $table->decimal('overdue_up_to_30_days_amount', 18, 2);
            $table->decimal('overdue_31_to_60_days_amount', 18, 2);
            $table->decimal('overdue_61_to_90_days_amount', 18, 2);
            $table->decimal('overdue_91_to_120_days_amount', 18, 2);
            $table->decimal('overdue_121_to_150_days_amount', 18, 2);
            $table->decimal('overdue_151_to_180_days_amount', 18, 2);
            $table->decimal('overdue_181_to_360_days_amount', 18, 2);
            $table->decimal('overdue_over_360_days_amount', 18, 2);

            $table->decimal('prepaid_up_to_30_days_amount', 18, 2);
            $table->decimal('prepaid_31_to_60_days_amount', 18, 2);
            $table->decimal('prepaid_61_to_90_days_amount', 18, 2);
            $table->decimal('prepaid_91_to_120_days_amount', 18, 2);
            $table->decimal('prepaid_121_to_150_days_amount', 18, 2);
            $table->decimal('prepaid_151_to_180_days_amount', 18, 2);
            $table->decimal('prepaid_181_to_360_days_amount', 18, 2);
            $table->decimal('prepaid_over_360_days_amount', 18, 2);

            $table->decimal('linked_credits_up_to_30_days_amount', 18, 2);
            $table->decimal('linked_credits_31_to_60_days_amount', 18, 2);
            $table->decimal('linked_credits_61_to_90_days_amount', 18, 2);
            $table->decimal('linked_credits_91_to_120_days_amount', 18, 2);
            $table->decimal('linked_credits_121_to_150_days_amount', 18, 2);
            $table->decimal('linked_credits_151_to_180_days_amount', 18, 2);
            $table->decimal('linked_credits_181_to_360_days_amount', 18, 2);
            $table->decimal('linked_credits_over_360_days_amount', 18, 2);

            $table->decimal('guarantees_value_amount', 18, 2)->nullable();
            $table->decimal('total_prepayment_amount', 18, 2);
            $table->decimal('top_five_debtors_concentration_ratio', 12, 6)->nullable();
            $table->decimal('total_outstanding_balance_amount', 18, 2);
            $table->decimal('portfolio_ltv_ratio', 12, 6)->nullable();
            $table->decimal('sale_ltv_ratio', 12, 6)->nullable();
            $table->decimal('portfolio_duration_years', 12, 6)->nullable();
            $table->decimal('portfolio_duration_months', 12, 6)->nullable();
            $table->text('average_rate_details')->nullable();
            $table->json('summary_payload')->nullable();
            $table->timestamps();

            $table->foreign('emission_id', 'receivables_summary_emission_foreign')
                ->references('id')
                ->on('emissions')
                ->cascadeOnDelete();
            $table->unique(['emission_id', 'reference_month']);
            $table->index(['reference_month', 'portfolio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');

        if (Schema::hasTable('receivables_legacy_details') && (! Schema::hasTable('receivables'))) {
            Schema::rename('receivables_legacy_details', 'receivables');
        }
    }
};
