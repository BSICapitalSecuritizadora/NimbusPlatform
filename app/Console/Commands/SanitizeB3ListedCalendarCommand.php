<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\B3ListedCalendarSanitationService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class SanitizeB3ListedCalendarCommand extends Command
{
    protected $signature = 'pu:business-calendar:sanitize-b3-listed
        {--execute : Executa a remoção transacional após reauditoria}
        {--checksum= : Checksum exibido pela auditoria imediatamente anterior}
        {--user= : ID do responsável pela execução}';

    protected $description = 'Reaudita e remove somente o lote inferido conhecido de B3_LISTED_TRADING.';

    public function handle(B3ListedCalendarSanitationService $sanitation): int
    {
        $audit = $sanitation->audit();
        $this->info('Reauditoria de B3_LISTED_TRADING');
        $this->table(['Métrica', 'Valor'], [
            ['Linhas no calendário', $audit['calendar_count']],
            ['Período no calendário', sprintf('%s a %s', $audit['calendar_from'] ?? '—', $audit['calendar_to'] ?? '—')],
            ['Candidatas estritas', $audit['candidate_count']],
            ['Checksum candidato', $audit['checksum']],
            ['Gates satisfeitos', $audit['safe_to_execute'] ? 'sim' : 'não'],
            ['Consumidores', array_sum($audit['consumers'])],
            ['Anos confirmados', collect($audit['governance'])->where('status', 'confirmed')->count()],
        ]);

        if (! $this->option('execute')) {
            $this->warn('Auditoria somente leitura. Para executar, repita com --execute, --checksum e --user.');

            return $audit['safe_to_execute'] ? self::SUCCESS : self::FAILURE;
        }

        $checksum = trim((string) $this->option('checksum'));
        $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT);

        if ($checksum === '') {
            $this->error('Informe --checksum com o fingerprint exibido pela auditoria.');

            return self::FAILURE;
        }

        if ($userId === false || ! User::query()->whereKey($userId)->exists()) {
            $this->error('Informe --user com o ID de um responsável existente.');

            return self::FAILURE;
        }

        try {
            $result = $sanitation->sanitize($checksum, $userId);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Saneamento concluído: %d datas e %d metadados anuais removidos.',
            $result['removed_dates'],
            $result['removed_years'],
        ));
        $this->line(sprintf('Batch UUID: %s', $result['batch_uuid']));
        $this->line(sprintf('Import run de auditoria: %d', $result['import_run_id']));
        $this->line(sprintf(
            'Depois: %d linha(s), período %s a %s, checksum %s.',
            $result['after']['count'],
            $result['after']['from'] ?? '—',
            $result['after']['to'] ?? '—',
            $result['after']['checksum'],
        ));

        return self::SUCCESS;
    }
}
