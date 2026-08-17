<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proposal_contacts', function (Blueprint $table) {
            $table->boolean('is_whatsapp')->nullable()->after('whatsapp');
            $table->boolean('whatsapp_contact_consent')->nullable()->after('is_whatsapp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposal_contacts', function (Blueprint $table) {
            $table->dropColumn(['is_whatsapp', 'whatsapp_contact_consent']);
        });
    }
};
