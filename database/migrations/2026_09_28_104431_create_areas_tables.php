<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Códigos de `App\Enums\BusinessArea` na data desta migration. Ficam fixos
     * aqui de propósito: uma área nova entra com a própria migration.
     *
     * @var list<string>
     */
    private const AREA_CODES = [
        'pu_curve',
        'emissions',
        'obligations',
        'guarantees',
        'legal_instruments',
        'construction_operations',
        'sales_boards',
        'clients_contracts',
        'registrations',
        'commercial',
        'recruitment',
        'external_documents',
        'reports',
        'administration',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->timestamps();
        });

        Schema::create('area_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['area_id', 'user_id']);
        });

        $now = now();

        DB::table('areas')->insert(array_map(
            fn (string $code): array => ['code' => $code, 'created_at' => $now, 'updated_at' => $now],
            self::AREA_CODES,
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_user');
        Schema::dropIfExists('areas');
    }
};
