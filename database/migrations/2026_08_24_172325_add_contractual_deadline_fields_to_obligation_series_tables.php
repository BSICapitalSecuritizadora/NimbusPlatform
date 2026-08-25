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
     * @var list<string>
     */
    private array $columns = [
        'relative_offset_quantity',
        'relative_offset_unit',
        'relative_offset_direction',
        'anchor_description',
        'initial_date_inclusion',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'relative_offset_quantity')) {
                    $table->unsignedSmallInteger('relative_offset_quantity')->nullable()->after('due_offset_days');
                }

                if (! Schema::hasColumn($tableName, 'relative_offset_unit')) {
                    $table->string('relative_offset_unit', 32)->nullable()->after('relative_offset_quantity');
                }

                if (! Schema::hasColumn($tableName, 'relative_offset_direction')) {
                    $table->string('relative_offset_direction', 16)->nullable()->after('relative_offset_unit');
                }

                if (! Schema::hasColumn($tableName, 'anchor_description')) {
                    $table->string('anchor_description')->nullable()->after('relative_offset_direction');
                }

                if (! Schema::hasColumn($tableName, 'initial_date_inclusion')) {
                    $table->string('initial_date_inclusion', 16)->nullable()->after('anchor_description');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            $existingColumns = array_values(array_filter(
                $this->columns,
                fn (string $column): bool => Schema::hasColumn($tableName, $column),
            ));

            if ($existingColumns === []) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($existingColumns) {
                $table->dropColumn($existingColumns);
            });
        }
    }
};
