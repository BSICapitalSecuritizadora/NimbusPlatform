<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_builder_review_attachments';

    /**
     * Os arquivos da resposta da construtora, um por linha.
     *
     * A resposta costuma ser um e-mail salvo em PDF mais a planilha que veio
     * com ele, e por isso não cabe em colunas da própria validação. Cada linha
     * guarda onde o arquivo está no disco privado e os metadados derivados do
     * arquivo gravado -- MIME, tamanho e SHA-256 --, nunca os que o navegador
     * declarou.
     *
     * A validação é registro de auditoria e não se apaga (FK RESTRICT nas
     * outras tabelas da rodada); a evidência dela segue a mesma regra. A
     * autoria usa SET NULL, como em todo o módulo: a conta pode sumir, o
     * arquivo e a trilha ficam.
     *
     * Tabela nova e vazia. No MySQL o Laravel cria as chaves estrangeiras em
     * comandos separados do CREATE, e o MySQL não desfaz DDL: uma falha entre
     * eles deixaria a tabela criada sem a FK, e a reexecução cairia em "table
     * exists". Por isso o CREATE é guardado pela existência da tabela e cada FK
     * é conferida pelas colunas -- no SQLite o nome da FK volta nulo.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sales_board_builder_review_id')
                    ->constrained('sales_board_builder_reviews', indexName: 'builder_review_attachments_review_foreign')
                    ->restrictOnDelete();
                $table->string('disk', 20);
                $table->string('path', 500);
                $table->string('original_name');
                $table->string('mime_type', 150)->nullable();
                $table->unsignedBigInteger('size_bytes');
                $table->char('checksum', 64)->nullable();
                $table->string('scan_status', 20)->default('pending');
                $table->foreignId('uploaded_by_user_id')->nullable()
                    ->constrained('users', indexName: 'builder_review_attachments_uploaded_by_foreign')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! $this->hasForeignKeyOn('sales_board_builder_review_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('sales_board_builder_review_id', 'builder_review_attachments_review_foreign')
                    ->references('id')
                    ->on('sales_board_builder_reviews')
                    ->restrictOnDelete();
            });
        }

        if (! $this->hasForeignKeyOn('uploaded_by_user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('uploaded_by_user_id', 'builder_review_attachments_uploaded_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function hasForeignKeyOn(string $column): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);
    }
};
