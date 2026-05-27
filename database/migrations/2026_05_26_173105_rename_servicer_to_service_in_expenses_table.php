<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('expenses')
            ->where('category', 'Servicer')
            ->update(['category' => 'Service']);
    }

    public function down(): void
    {
        DB::table('expenses')
            ->where('category', 'Service')
            ->update(['category' => 'Servicer']);
    }
};
