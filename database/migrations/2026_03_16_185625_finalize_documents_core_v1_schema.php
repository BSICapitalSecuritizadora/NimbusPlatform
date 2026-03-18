<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultDisk = config('filesystems.default', 'public');
        $effectiveDisk = $defaultDisk === 'local' ? 'public' : $defaultDisk;
        $allowedCategories = [
            'anuncios',
            'assembleias',
            'convocacoes_assembleias',
            'demonstracoes_financeiras',
            'documentos_operacao',
            'fatos_relevantes',
            'relatorios_anuais',
        ];

        Schema::table('documents', function (Blueprint $table) {
            $table->string('storage_disk')->nullable()->after('file_size');
        });

        DB::table('documents')
            ->where('category', 'informativos')
            ->update(['category' => 'anuncios']);

        DB::table('documents')
            ->whereNull('category')
            ->orWhere('category', '')
            ->update(['category' => 'documentos_operacao']);

        DB::table('documents')
            ->whereNotIn('category', $allowedCategories)
            ->update(['category' => 'documentos_operacao']);

        DB::table('documents')
            ->whereNull('storage_disk')
            ->orWhere('storage_disk', '')
            ->update(['storage_disk' => $effectiveDisk]);

        Schema::table('documents', function (Blueprint $table) {
            $table->string('category')->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('category')->nullable()->change();
            $table->dropColumn('storage_disk');
        });
    }
};
