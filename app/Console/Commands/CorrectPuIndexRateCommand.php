<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Correção explícita de uma observação de índice já registrada.
 *
 * Único caminho para mudar o valor de uma data que já existe: sincronização e
 * importação nunca sobrescrevem. Exige motivo e o usuário que corrige; o valor
 * anterior vai para o livro de correções e as curvas homologadas que usavam a
 * observação ficam marcadas para reprocessamento, sem que nenhuma linha
 * homologada seja reescrita.
 */
class CorrectPuIndexRateCommand extends Command
{
    protected $signature = 'pu:index-rates:correct
        {--indexer=cdi : cdi | ipca}
        {--date= : data da observação (YYYY-MM-DD)}
        {--value= : novo valor, na unidade da série (CDI em % a.a. base 252)}
        {--reason= : motivo da correção (obrigatório)}
        {--user= : id ou e-mail de quem corrige (obrigatório)}
        {--source= : nova origem (opcional; mantém a atual se omitida)}
        {--source-reference= : nova referência de origem (opcional)}';

    protected $description = 'Corrige, com motivo e trilha, uma taxa de índice já registrada. As curvas homologadas que a usavam ficam marcadas para reprocessamento, sem reescrita.';

    public function handle(IndexRateCorrectionService $corrections): int
    {
        $indexer = match (strtolower((string) $this->option('indexer'))) {
            'cdi' => PuIndexer::Cdi,
            'ipca' => PuIndexer::Ipca,
            default => null,
        };

        if ($indexer === null) {
            $this->error('Indexador inválido. Use --indexer=cdi ou --indexer=ipca.');

            return self::FAILURE;
        }

        foreach (['date', 'value', 'reason', 'user'] as $required) {
            if (blank($this->option($required))) {
                $this->error(sprintf('Informe --%s.', $required));

                return self::FAILURE;
            }
        }

        $userOption = (string) $this->option('user');
        $user = ctype_digit($userOption)
            ? User::query()->find((int) $userOption)
            : User::query()->where('email', $userOption)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('Usuário "%s" não encontrado.', $userOption));

            return self::FAILURE;
        }

        try {
            $correction = $corrections->correct(
                indexer: $indexer,
                rateDate: (string) $this->option('date'),
                newValue: (string) $this->option('value'),
                reason: (string) $this->option('reason'),
                correctedByUserId: (int) $user->getKey(),
                newSource: $this->option('source') !== null ? (string) $this->option('source') : null,
                newSourceReference: $this->option('source-reference') !== null ? (string) $this->option('source-reference') : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Correção #%d: %s de %s passou de %s para %s.',
            $correction->id,
            $correction->indexer,
            $correction->rate_date?->toDateString(),
            (string) $correction->previous_rate_value,
            (string) $correction->new_rate_value,
        ));

        foreach ($correction->affected_curve_versions ?? [] as $affected) {
            $this->warn(sprintf(
                'Curva %s (emissão %d, %s) usava a observação desde %s: as linhas gravadas foram mantidas; a versão governada exige reprocessamento.',
                $affected['calculation_version'],
                $affected['emission_id'],
                $affected['status'],
                $affected['first_dependent_date'],
            ));
        }

        return self::SUCCESS;
    }
}
