<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `contracts.client_id` stops being where the buyer lives.
     *
     * From here on the buyers of a contract are the rows of `contract_clients`,
     * and nothing writes this column any more: a contract created from now on
     * carries NULL, which is the honest value -- there is no "main buyer" to put
     * in it, and picking one would invent a hierarchy the domain does not have.
     *
     * The column, its foreign key and its index all stay. Contracts recorded
     * before this release keep the value they had, which is what makes rolling
     * the code back to the previous release survivable for them. Dropping it is
     * a later, separately authorised release.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });
    }

    /**
     * Only reversible while no contract has been created without a buyer in the
     * column -- which is every contract created by the release this migration
     * belongs to. Reverting the schema after that would have nothing to put in
     * the column for those rows, and this migration will not invent one.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
