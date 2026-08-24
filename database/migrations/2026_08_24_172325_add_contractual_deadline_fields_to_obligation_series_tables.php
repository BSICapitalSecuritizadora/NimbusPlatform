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
        Schema::table('obligation_series', function (Blueprint $table) {
            $table->unsignedSmallInteger('relative_offset_quantity')->nullable()->after('due_offset_days');
            $table->string('relative_offset_unit', 32)->nullable()->after('relative_offset_quantity');
            $table->string('relative_offset_direction', 16)->nullable()->after('relative_offset_unit');
            $table->string('anchor_description')->nullable()->after('relative_offset_direction');
            $table->string('initial_date_inclusion', 16)->nullable()->after('anchor_description');
        });

        Schema::table('obligation_series_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('relative_offset_quantity')->nullable()->after('due_offset_days');
            $table->string('relative_offset_unit', 32)->nullable()->after('relative_offset_quantity');
            $table->string('relative_offset_direction', 16)->nullable()->after('relative_offset_unit');
            $table->string('anchor_description')->nullable()->after('relative_offset_direction');
            $table->string('initial_date_inclusion', 16)->nullable()->after('anchor_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligation_series_rules', function (Blueprint $table) {
            $table->dropColumn([
                'relative_offset_quantity',
                'relative_offset_unit',
                'relative_offset_direction',
                'anchor_description',
                'initial_date_inclusion',
            ]);
        });

        Schema::table('obligation_series', function (Blueprint $table) {
            $table->dropColumn([
                'relative_offset_quantity',
                'relative_offset_unit',
                'relative_offset_direction',
                'anchor_description',
                'initial_date_inclusion',
            ]);
        });
    }
};
