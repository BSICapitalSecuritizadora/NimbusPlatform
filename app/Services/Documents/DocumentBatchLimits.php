<?php

namespace App\Services\Documents;

use Illuminate\Support\Number;

/**
 * Limites explícitos do cadastro em lote, lidos de `config('uploads.document_batch')`.
 *
 * Ficam num objeto próprio porque são consumidos em três lugares — a validação
 * do `FileUpload`, a checagem do lote inteiro no envio e as mensagens exibidas
 * ao usuário — e um valor divergente entre eles viraria um limite que a
 * interface promete e o servidor não cumpre (ou o contrário).
 */
class DocumentBatchLimits
{
    public function maxFiles(): int
    {
        return max(1, (int) config('uploads.document_batch.max_files', 20));
    }

    public function maxFileKilobytes(): int
    {
        return max(1, (int) config('uploads.document_batch.max_kb', 25600));
    }

    public function maxFileBytes(): int
    {
        return $this->maxFileKilobytes() * 1024;
    }

    public function maxTotalKilobytes(): int
    {
        return max(1, (int) config('uploads.document_batch.total_max_kb', 81920));
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalKilobytes() * 1024;
    }

    /**
     * Tempo de processamento que o lote pode consumir no servidor. O
     * {@see DocumentBatchCreator} sempre processa ao menos um arquivo, então um
     * valor baixo apenas encurta o lote — nunca o trava.
     */
    public function timeBudgetSeconds(): int
    {
        return max(0, (int) config('uploads.document_batch.time_budget_seconds', 90));
    }

    /**
     * Tipos MIME aceitos: os mesmos do cadastro individual, sem lista paralela.
     *
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return array_values((array) config('uploads.document.allowed_mimes', []));
    }

    /**
     * Motivo pelo qual o lote inteiro não pode ser processado, ou `null` quando
     * ele está dentro dos limites. Diferente das rejeições por arquivo, isto
     * barra o lote antes de qualquer gravação.
     *
     * @param  array<int, int>  $fileSizesInBytes
     */
    public function batchRejectionReason(array $fileSizesInBytes): ?string
    {
        if ($fileSizesInBytes === []) {
            return 'Selecione ao menos um arquivo para cadastrar.';
        }

        if (count($fileSizesInBytes) > $this->maxFiles()) {
            return "O lote aceita no máximo {$this->maxFiles()} arquivos por vez. Você selecionou "
                .count($fileSizesInBytes).'. Divida o cadastro em lotes menores.';
        }

        $totalBytes = array_sum($fileSizesInBytes);

        if ($totalBytes > $this->maxTotalBytes()) {
            return 'O lote soma '.Number::fileSize($totalBytes).', acima do limite de '
                .Number::fileSize($this->maxTotalBytes()).'. Remova alguns arquivos e tente novamente.';
        }

        return null;
    }

    public function summaryText(): string
    {
        return "Até {$this->maxFiles()} arquivos por lote · máximo de "
            .Number::fileSize($this->maxFileBytes()).' por arquivo · '
            .Number::fileSize($this->maxTotalBytes()).' no total.';
    }
}
