<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Models\PuObligationRefreshRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Recuperação das atualizações de obrigações do PU (Fase 6).
 *
 * Sem opções, é a varredura agendada: executa os pedidos vencidos (pendentes
 * depois da carência, novas tentativas agendadas, execuções interrompidas). Com
 * `--list`, mostra os pedidos abertos. Com `--retry`, retoma -- em nome de um
 * usuário com `pu.operations.recover` e com motivo -- os pedidos esgotados ou
 * bloqueados de uma emissão. Nenhum modo homologa, liquida, decide conflito ou
 * escreve curva: só recompõe o que é derivado dos fatos.
 */
class RecoverPuObligationRefreshCommand extends Command
{
    protected $signature = 'pu:obligations:recover
        {--list : Lista os pedidos de atualização abertos}
        {--retry : Retoma os pedidos esgotados ou bloqueados da emissão}
        {--emission= : Emissão (ID) da retomada}
        {--user= : id ou e-mail de quem retoma (obrigatório com --retry)}
        {--reason= : motivo da retomada (obrigatório com --retry)}';

    protected $description = 'Executa os pedidos vencidos de atualização das obrigações do PU, lista os abertos ou retoma (com autorização) os esgotados e bloqueados.';

    public function handle(PuObligationRefreshRecovery $recovery): int
    {
        if ((bool) $this->option('list')) {
            $this->table(['Pedido', 'Emissão', 'Gatilho', 'Situação', 'Tentativas', 'Próxima tentativa', 'Última falha'], PuObligationRefreshRequest::query()->open()->orderBy('id')->get()->map(fn (PuObligationRefreshRequest $request): array => [
                $request->id,
                $request->emission_id,
                $request->trigger,
                $request->status->label(),
                $request->attempts,
                $request->next_attempt_at?->format('d/m/Y H:i') ?? '—',
                $request->last_error_category?->label() ?? '—',
            ])->all());

            return self::SUCCESS;
        }

        if ((bool) $this->option('retry')) {
            return $this->retry($recovery);
        }

        $outcomes = $recovery->recoverDue();
        $this->renderOutcomes($outcomes);
        $recovery->pruneCompleted();

        return collect($outcomes)->contains(fn (PuObligationRefreshOutcome $outcome): bool => in_array($outcome->status, [PuObligationRefreshOutcome::EXHAUSTED, PuObligationRefreshOutcome::BLOCKED], true))
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function retry(PuObligationRefreshRecovery $recovery): int
    {
        $userOption = (string) $this->option('user');
        $user = ctype_digit($userOption)
            ? User::query()->find((int) $userOption)
            : User::query()->where('email', $userOption)->first();

        if (blank($this->option('emission')) || ! $user instanceof User) {
            $this->error('Informe --emission e --user (usuário existente) para retomar.');

            return self::FAILURE;
        }

        try {
            $outcome = $recovery->retry((int) $this->option('emission'), $user, (string) $this->option('reason'));
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderOutcomes([$outcome]);

        return $outcome->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<PuObligationRefreshOutcome>  $outcomes
     */
    private function renderOutcomes(array $outcomes): void
    {
        if ($outcomes === []) {
            $this->line('Nenhum pedido de atualização vencido.');

            return;
        }

        $this->table(['Emissão', 'Via', 'Resultado', 'Pedidos', 'Cobertos', 'Tentativa', 'Falha'], array_map(fn (PuObligationRefreshOutcome $outcome): array => [
            $outcome->emissionId,
            $outcome->via,
            $outcome->status,
            implode(',', $outcome->claimedRequestIds) ?: '—',
            implode(',', $outcome->coveredRequestIds) ?: '—',
            $outcome->attempts,
            $outcome->failureCategory?->label() ?? '—',
        ], $outcomes));
    }
}
