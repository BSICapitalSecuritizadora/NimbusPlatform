<?php

namespace App\Console\Commands;

use App\Enums\VacancyStatus;
use App\Models\User;
use App\Models\Vacancy;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCloseVacancies extends Command
{
    protected $signature = 'vacancies:auto-close {--dry-run : Apenas relata o que seria encerrado}';

    protected $description = 'Encerra automaticamente vagas publicadas cuja data de expiração já passou';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $query = Vacancy::query()
            ->where('status', VacancyStatus::Published->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        $count = $query->count();

        if ($count === 0) {
            $this->components->info('Nenhuma vaga expirada para encerrar.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->components->info("Seriam encerradas {$count} vaga(s) expirada(s).");

            return self::SUCCESS;
        }

        $closed = 0;

        $query->eachById(function (Vacancy $vacancy) use (&$closed): void {
            $vacancy->update([
                'status' => VacancyStatus::Closed,
                'closed_at' => now(),
            ]);

            $closed++;
        });

        Log::info('Auto-close de vagas concluído.', ['closed' => $closed]);

        $this->components->info("Encerradas {$closed} vaga(s) expirada(s).");

        // Notify recruitment users (not spamming on dry-run; idempotent because only touches expired published)
        if ($closed > 0) {
            $recipients = User::permission('recruitment.vacancies.view')->get();

            foreach ($recipients as $user) {
                Notification::make()
                    ->title($closed.' vaga(s) encerrada(s) automaticamente por expiração.')
                    ->body('As vagas com data de expiração vencida foram movidas para Encerradas.')
                    ->warning()
                    ->sendToDatabase($user);
            }
        }

        return self::SUCCESS;
    }
}
