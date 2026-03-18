<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('emissions')
            ->whereNull('type')
            ->orWhere('type', '')
            ->update(['type' => 'CR']);

        DB::table('emissions')
            ->whereIn('status', ['Ativo', 'ativo'])
            ->update(['status' => 'active']);

        DB::table('emissions')
            ->whereIn('status', ['Encerrado', 'encerrado'])
            ->update(['status' => 'closed']);

        DB::table('emissions')
            ->whereIn('status', ['Rascunho', 'rascunho'])
            ->update(['status' => 'draft']);

        DB::table('emissions')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'draft']);

        Schema::table('emissions', function (Blueprint $table) {
            $table->string('type')->change();
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
            $table->string('status')->default('draft')->change();
        });
    }
};
