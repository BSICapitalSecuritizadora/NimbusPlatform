<?php

use App\Enums\MalwareScanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scan_status` cobria só `proposal_files`, `nimbus_submission_files` e
 * `job_applications`. As demais tabelas de upload ficaram de fora, e com elas os
 * downloads correspondentes — evidências de obrigação, biblioteca do portal e
 * documentos do site — não tinham como consultar o resultado da varredura.
 *
 * Os registros que já existem são marcados como `clean`: eles são anteriores à
 * coluna e mantê-los em `pending` faria todo download existente responder 404
 * no primeiro deploy. Uma varredura retroativa do storage, se desejada, é
 * operação separada.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> tabela => coluna após a qual inserir
     */
    private const TABLES = [
        'obligation_evidences' => 'size',
        'nimbus_general_documents' => 'file_mime',
        'nimbus_documents' => 'file_mime',
        'documents' => 'file_size',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $afterColumn) {
            Schema::table($table, function (Blueprint $blueprint) use ($afterColumn): void {
                $blueprint->string('scan_status', 20)
                    ->default(MalwareScanStatus::Pending->value)
                    ->index()
                    ->after($afterColumn);
            });

            DB::table($table)->update(['scan_status' => MalwareScanStatus::Clean->value]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('scan_status');
            });
        }
    }
};
