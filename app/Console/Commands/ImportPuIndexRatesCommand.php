<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Importação de índices PUBLICADOS por CSV, a mesma da tela, com uma opção a
 * mais: confirmar explicitamente os valores retidos por parecerem outra unidade
 * (dez vezes maiores ou menores que a observação anterior).
 */
class ImportPuIndexRatesCommand extends Command
{
    protected $signature = 'pu:index-rates:import
        {path : caminho do CSV (rate_date,rate_value[,notes])}
        {--indexer=cdi : cdi | ipca}
        {--source=manual_import : origem gravada nas linhas novas}
        {--user= : id ou e-mail de quem importa}
        {--confirm-outliers : registra também os valores retidos para confirmação}';

    protected $description = 'Importa índices publicados (CSV) sem sobrescrever datas já registradas; conflitos e recusas são listados linha a linha.';

    public function handle(IndexRateImportService $imports): int
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

        $userId = null;

        if (filled($this->option('user'))) {
            $userOption = (string) $this->option('user');
            $user = ctype_digit($userOption)
                ? User::query()->find((int) $userOption)
                : User::query()->where('email', $userOption)->first();

            if (! $user instanceof User) {
                $this->error(sprintf('Usuário "%s" não encontrado.', $userOption));

                return self::FAILURE;
            }

            $userId = (int) $user->getKey();
        }

        try {
            $result = $imports->importPublished(
                $indexer,
                (string) $this->argument('path'),
                (string) $this->option('source'),
                $userId,
                (bool) $this->option('confirm-outliers'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d data(s) nova(s), %d idêntica(s), %d conflito(s), %d retida(s) para confirmação.',
            $result['imported'],
            $result['unchanged'],
            count($result['conflicts']),
            count($result['needs_confirmation']),
        ));

        foreach ($result['errors'] as $message) {
            $this->warn($message);
        }

        return self::SUCCESS;
    }
}
