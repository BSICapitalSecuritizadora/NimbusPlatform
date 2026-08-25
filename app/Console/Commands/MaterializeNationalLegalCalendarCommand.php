<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class MaterializeNationalLegalCalendarCommand extends Command
{
    protected $signature = 'pu:business-calendar:materialize-national-holidays
        {--from-year=2026 : Primeiro ano a projetar}
        {--to-year=2031 : Último ano a projetar}
        {--user= : ID do responsável pela captura e materialização}';

    protected $description = 'Materializa feriados nacionais legais a partir de regras federais versionadas, sem confirmar os anos.';

    public function handle(NationalLegalHolidayMaterializationService $materializer): int
    {
        $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT);

        if ($userId === false || ! User::query()->whereKey($userId)->exists()) {
            $this->error('Informe --user com o ID de um responsável existente.');

            return self::FAILURE;
        }

        try {
            $result = $materializer->materialize(
                (int) $this->option('from-year'),
                (int) $this->option('to-year'),
                $userId,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s materializado de %d a %d. Batch: %s',
            $result['calendar_code'],
            $result['from_year'],
            $result['to_year'],
            $result['batch_uuid'],
        ));
        $this->table(
            ['Ano', 'Feriados', 'Inseridos', 'Alterados', 'Cobertura', 'Governança', 'Checksum'],
            collect($result['years'])->map(fn (array $year): array => [
                $year['year'],
                $year['holidays'],
                $year['inserted'],
                $year['changed'],
                $year['coverage_status'],
                $year['status'],
                $year['checksum'],
            ])->all(),
        );
        $this->warn('Nenhum ano foi confirmado automaticamente; a revisão administrativa continua obrigatória.');

        return self::SUCCESS;
    }
}
