<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda o SHA-256 do arquivo gravado para que documentos iguais possam ser
 * identificados com certeza, e não por semelhança de nome.
 *
 * A coluna é anulável porque os documentos já cadastrados não têm o valor: ele
 * é preenchido quando o arquivo é (re)enviado e, para o acervo antigo, pelo
 * comando `documents:backfill-checksums`. Enquanto estiver nula, a detecção de
 * duplicidade daquele documento continua sendo apenas o indício por nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable()->after('file_size');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['checksum']);
            $table->dropColumn('checksum');
        });
    }
};
