<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the legacy Fund identity application-aware.
     *
     * Limitation: UNIQUE(emission_id, fund_application_id, account) blocks a
     * valid configuration — one Emission with several Funds sharing the
     * classification and the physical account string but linked to distinct
     * BankApplications. Linked funds derive the legacy `account` column from
     * the same BankAccount, so the second fund of a second product in the
     * same account is rejected.
     *
     * Replacement: UNIQUE(emission_id, fund_application_id, account,
     * bank_application_identity), where the generated identity column is
     * COALESCE(bank_application_id, 0). Rows without a linked application
     * keep the exact legacy identity (all map to 0); linked rows are
     * distinguished by their application. This migration cannot fail on
     * existing data: any two rows colliding under the new key already
     * collided under the old key, which the old constraint forbade.
     *
     * Auto-increment ids start at 1, so the 0 sentinel never matches a real
     * BankApplication. No Fund row is modified — identity only.
     *
     * Ordering: the new key is added BEFORE the old one is dropped. InnoDB
     * silently uses the old unique (leftmost emission_id) as the supporting
     * index of the funds.emission_id foreign key — there is no separate
     * single-column index — so dropping it first fails with error 1553.
     */
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_application_identity')->storedAs('coalesce(bank_application_id, 0)')->after('bank_application_id');
        });

        Schema::table('funds', function (Blueprint $table) {
            $table->unique(
                ['emission_id', 'fund_application_id', 'account', 'bank_application_identity'],
                'funds_emission_application_account_identity_unique'
            );
        });

        Schema::table('funds', function (Blueprint $table) {
            $table->dropUnique('funds_emission_application_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Best-effort only: rows created under the new rule (same operation,
     * classification and account with distinct applications) violate the
     * legacy key, and re-adding it fails while such rows exist. Prefer a
     * forward fix in production.
     */
    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->unique(['emission_id', 'fund_application_id', 'account'], 'funds_emission_application_account_unique');
        });

        Schema::table('funds', function (Blueprint $table) {
            $table->dropUnique('funds_emission_application_account_identity_unique');
        });

        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn('bank_application_identity');
        });
    }
};
