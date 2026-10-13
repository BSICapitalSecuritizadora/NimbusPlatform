<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use App\Models\Emission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;

/**
 * Ativa ou suspende a operação do PU de uma emissão (Fase 7).
 *
 * Ferramenta de operador, não assistente de tela: cada execução é uma decisão
 * autorizada (`pu.operations.activate`), com motivo e -- para ativar -- a
 * referência da aprovação documentada, gravada no livro de ativação e na trilha
 * protegida. Ativar exige as operações do PU ligadas no ambiente e uma avaliação
 * de prontidão sem bloqueio; suspender vale sempre. Nenhum dos dois gera curva,
 * homologa, liquida ou corrige nada.
 */
class ChangePuOperationalStatusCommand extends Command
{
    protected $signature = 'pu:operations:activation
        {action : activate (ativar) ou suspend (suspender)}
        {emission : Emissão (ID)}
        {--user= : id ou e-mail de quem decide (precisa de pu.operations.activate)}
        {--reason= : motivo da decisão}
        {--approval-reference= : referência da aprovação documentada (obrigatória para ativar)}';

    protected $description = 'Ativa (com avaliação de prontidão e aprovação documentada) ou suspende a operação do PU de uma emissão. Decisão autorizada e auditada; não gera nem homologa curva.';

    public function handle(PuOperationalActivationService $activation): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['activate', 'suspend'], true)) {
            $this->error('Ação inválida: use activate ou suspend.');

            return self::FAILURE;
        }

        $emission = Emission::query()->find((int) $this->argument('emission'));
        $userOption = (string) $this->option('user');
        $user = ctype_digit($userOption)
            ? User::query()->find((int) $userOption)
            : User::query()->where('email', $userOption)->first();

        if (! $emission instanceof Emission || ! $user instanceof User) {
            $this->error('Informe uma emissão existente e --user (usuário existente) para decidir.');

            return self::FAILURE;
        }

        try {
            $change = $action === 'activate'
                ? $activation->activate($emission, $user, (string) $this->option('reason'), (string) $this->option('approval-reference'))
                : $activation->suspend($emission, $user, (string) $this->option('reason'));
        } catch (AuthorizationException|PuOperationalActivationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Emissão #%d: %s -> %s (decisão #%d, por %s).',
            $emission->id,
            $change->previous_status->label(),
            $change->status->label(),
            $change->id,
            $user->email,
        ));

        return self::SUCCESS;
    }
}
