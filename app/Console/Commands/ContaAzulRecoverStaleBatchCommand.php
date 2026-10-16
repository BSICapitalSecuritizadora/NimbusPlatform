<?php

namespace App\Console\Commands;

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\ContaAzulImportBatch;
use App\Models\User;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('conta-azul:recover-stale-batch {batch : ID da importação interrompida} {--reason= : Motivo da recuperação (obrigatório)} {--as-user= : ID ou e-mail do responsável (obrigatório)}')]
#[Description('Recupera uma importação Conta Azul interrompida, marcando-a como abandonada para permitir nova tentativa')]
class ContaAzulRecoverStaleBatchCommand extends Command
{
    /**
     * Explicitly invoked recovery. No schedule wires this command in Phase
     * 3B: every recovery names its batch, its reason and its responsible
     * user, and the service refuses batches with recent activity.
     */
    public function handle(ContaAzulStaleBatchRecoveryService $recovery): int
    {
        $batch = ContaAzulImportBatch::query()->whereKey($this->argument('batch'))->first();

        if (! $batch instanceof ContaAzulImportBatch) {
            $this->error('Importação não encontrada.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        if (! $actor instanceof User) {
            $this->error('Informe um responsável válido em --as-user (ID ou e-mail de usuário com permissão de recuperação).');

            return self::FAILURE;
        }

        $reason = (string) ($this->option('reason') ?? '');

        try {
            $recovered = $recovery->recover($actor, $batch, $reason);
        } catch (ContaAzulReconciliationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Importação #{$recovered->getKey()} marcada como abandonada. Bills preservados; retome com uma nova importação referenciando este lote.");

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $identifier = $this->option('as-user');

        if (! is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        $identifier = trim($identifier);

        return User::query()
            ->whereKey($identifier)
            ->orWhere('email', $identifier)
            ->first();
    }
}
