<?php

namespace App\Console\Commands;

use App\Models\Proposal;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckProposalStaleItemsCommand extends Command
{
    protected $signature = 'proposals:check-stale';

    protected $description = 'Verifica propostas sem responsável ou paradas há muito tempo e notifica os usuários adequados.';

    public function handle(): int
    {
        $this->checkUnassignedProposals();
        $this->checkStaleProposals();

        $this->info('Verificação de propostas paradas concluída.');

        return self::SUCCESS;
    }

    protected function checkUnassignedProposals(): void
    {
        // Propostas sem responsável após 24 horas
        $unassigned = Proposal::query()
            ->whereNull('assigned_representative_id')
            ->where('created_at', '<=', now()->subHours(24))
            // Para não notificar pra sempre, podemos checar se já notificamos hoje.
            // Para simplificar, limitamos a checagem no intervalo entre 24 e 48 horas, assim notifica uma vez por dia.
            ->where('created_at', '>', now()->subHours(48))
            ->get();

        if ($unassigned->isEmpty()) {
            return;
        }

        // Notificar super-admins ou quem faz a triagem
        $admins = User::role(['super-admin', 'admin'])->get();

        foreach ($unassigned as $proposal) {
            foreach ($admins as $admin) {
                Notification::make()
                    ->title('Proposta sem responsável')
                    ->body("A proposta da empresa {$proposal->company?->company_name} está sem responsável há mais de 24 horas.")
                    ->warning()
                    ->sendToDatabase($admin);
            }
        }

        Log::info('Propostas sem responsável notificadas: '.$unassigned->count());
    }

    protected function checkStaleProposals(): void
    {
        // Propostas atribuídas mas sem mudança de status há mais de 7 dias
        // Vamos considerar "parada" se completed_at for nulo e distribuída há mais de 7 dias e o status for inicial
        $stale = Proposal::query()
            ->whereNotNull('assigned_representative_id')
            ->whereNull('completed_at')
            ->whereIn('status', ['triage', 'analysis'])
            ->where('updated_at', '<=', now()->subDays(7))
            ->where('updated_at', '>', now()->subDays(8))
            ->get();

        foreach ($stale as $proposal) {
            $representative = $proposal->representative;
            if ($representative && $representative->user_id) {
                $user = User::find($representative->user_id);
                if ($user) {
                    Notification::make()
                        ->title('Proposta parada')
                        ->body("A proposta {$proposal->company?->company_name} está sem atualização há mais de 7 dias.")
                        ->warning()
                        ->sendToDatabase($user);
                }
            }
        }

        Log::info('Propostas paradas há mais de 7 dias notificadas: '.$stale->count());
    }
}
