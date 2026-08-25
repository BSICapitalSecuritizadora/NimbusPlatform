<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'obligation_series',
        'obligation_series_rules',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasColumn($tableName, 'due_offset_days')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedSmallInteger('due_offset_days')->nullable()->after('due_offset_months');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasColumn($tableName, 'due_offset_days')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('due_offset_days');
            });
        }
    }
};
