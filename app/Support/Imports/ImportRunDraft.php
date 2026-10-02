<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Models\ImportRun;
use App\Support\ActivityLog\LogBatch;

/**
 * O registro de uma importação antes de ela acontecer: tipo, arquivo, quem e o
 * escopo.
 *
 * {@see self::open()} cria o {@see ImportRun} com os contadores zerados e
 * precisa ser chamado dentro da transação do importador: assim o id já existe
 * para carimbar `import_run_id` nas linhas criadas em lote, e uma gravação
 * desfeita leva o registro junto -- nunca sobra execução de uma importação que
 * não aconteceu. O batch da trilha é o que estiver aberto no momento.
 */
final readonly class ImportRunDraft
{
    public function __construct(
        public string $type,
        public string $fileName,
        public ?string $checksum,
        public ?string $filePath,
        public ?int $userId,
        public ?int $contractId = null,
    ) {}

    public function open(): ImportRun
    {
        return ImportRun::query()->create([
            'type' => $this->type,
            'file_name' => $this->fileName,
            'checksum' => $this->checksum,
            'file_path' => $this->filePath,
            'batch_uuid' => app(LogBatch::class)->getUuid(),
            'user_id' => $this->userId,
            'contract_id' => $this->contractId,
        ]);
    }
}
