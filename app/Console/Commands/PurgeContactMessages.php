<?php

namespace App\Console\Commands;

use App\Models\ContactMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Elimina mensagens do formulário de contato além do prazo de retenção.
 *
 * Cada mensagem carrega nome, e-mail, telefone e texto livre — que costuma
 * conter mais dado pessoal do que os campos estruturados.
 */
class PurgeContactMessages extends Command
{
    protected $signature = 'lgpd:purge-contact-messages
        {--months= : Sobrescreve o prazo de retenção configurado}
        {--dry-run : Apenas relata o que seria eliminado}';

    protected $description = 'Elimina mensagens de contato além do prazo de retenção';

    public function handle(): int
    {
        $retentionMonths = (int) ($this->option('months') ?? config('privacy.retention.contact_messages.months', 24));

        if ($retentionMonths <= 0) {
            $this->components->warn('Expurgo de mensagens de contato desativado (prazo de retenção não positivo).');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $cutoffDate = Carbon::now()->subMonths($retentionMonths)->startOfDay();

        $query = ContactMessage::query()->where('created_at', '<', $cutoffDate);

        $deletedMessages = $isDryRun ? $query->count() : $query->delete();

        Log::info('Expurgo de mensagens de contato concluído.', [
            'retention_months' => $retentionMonths,
            'cutoff' => $cutoffDate->toDateString(),
            'messages' => $deletedMessages,
            'dry_run' => $isDryRun,
        ]);

        $this->components->info(sprintf(
            '%s %d mensagem(ns) anteriores a %s.',
            $isDryRun ? 'Seriam eliminadas' : 'Eliminadas',
            $deletedMessages,
            $cutoffDate->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
