<?php

namespace App\Console\Commands;

use App\Concerns\MoneyFormatter;
use App\Models\GuaranteeSnapshot;
use App\Services\Guarantees\GuaranteeSnapshotOutstandingBalanceInvalidator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Rede diária da marca "desatualizada pelo saldo devedor".
 *
 * Homologar, invalidar e importar o Histórico de PU marcam as competências na
 * hora, pelo evento. O que não passa por esses três atos muda o saldo devedor
 * em silêncio: a extensão diária da curva oficial, a projeção legada gravada na
 * geração de uma curva ainda não homologada, a edição manual de uma linha do
 * Histórico e a integralização. Esta verificação compara, em centavos, o saldo
 * gravado de cada competência encerrada com o que a fonte responde hoje, e
 * marca o que divergiu.
 *
 * Só competências encerradas: o saldo do mês em curso muda a cada dia novo de
 * PU, e marcá-lo toda manhã só ensinaria a ignorar a marca. Idempotente: uma
 * competência já marcada não é comparada de novo até ser apurada outra vez.
 *
 * Agendada depois da extensão diária das curvas (`routes/console.php`).
 */
class MarkOutdatedGuaranteeCompetences extends Command
{
    public const REASON = 'Saldo devedor recalculado na verificação diária';

    protected $signature = 'guarantees:mark-outdated-competences
                            {--emission=* : Limita a verificação às Emissões informadas (id)}
                            {--dry-run : Lista as competências que seriam marcadas, sem gravar nada}';

    protected $description = 'Marca as competências de garantias encerradas cujo saldo devedor apurado hoje difere do gravado';

    public function handle(GuaranteeSnapshotOutstandingBalanceInvalidator $invalidator): int
    {
        $emissionIds = $this->requestedEmissionIds();

        if ($emissionIds === null) {
            return self::FAILURE;
        }

        $candidates = GuaranteeSnapshot::query()
            ->when($emissionIds !== [], fn (Builder $query): Builder => $query->whereIn('emission_id', $emissionIds))
            ->whereNull('outstanding_balance_outdated_at')
            ->whereDate('reference_month', '<', GuaranteeSnapshot::currentBusinessMonth())
            ->distinct()
            ->orderBy('emission_id')
            ->pluck('emission_id')
            ->map(fn (mixed $emissionId): int => (int) $emissionId);

        if ($this->option('dry-run')) {
            return $this->listWithoutWriting($invalidator, $candidates->all());
        }

        $marked = 0;
        $failed = 0;

        foreach ($candidates as $emissionId) {
            /*
             * Uma Emissão com problema não impede a verificação das outras: a
             * falha é registrada, o restante segue, e o comando termina com
             * erro para o agendador acusar.
             */
            try {
                $marked += $invalidator->markDrifted($emissionId, self::REASON, onlyEndedCompetences: true);
            } catch (Throwable $exception) {
                report($exception);

                $failed++;

                $this->error(sprintf('Emissão %d: a verificação falhou (%s).', $emissionId, $exception->getMessage()));
            }
        }

        $this->info(sprintf('Emissões verificadas: %d. Competências marcadas: %d.', $candidates->count(), $marked));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<int>  $emissionIds
     */
    private function listWithoutWriting(GuaranteeSnapshotOutstandingBalanceInvalidator $invalidator, array $emissionIds): int
    {
        $rows = [];

        foreach ($emissionIds as $emissionId) {
            foreach ($invalidator->drifted($emissionId, onlyEndedCompetences: true) as $drifted) {
                $rows[] = [
                    $emissionId,
                    $drifted['snapshot']->formatted_reference_month,
                    $this->money($drifted['recorded']),
                    $this->money($drifted['current']),
                    $drifted['snapshot']->isClosed() ? 'Sim' : 'Não',
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Emissão', 'Competência', 'Gravado', 'Apurado hoje', 'Fechada'], $rows);
        }

        $this->info(sprintf(
            'Emissões verificadas: %d. Competências que seriam marcadas: %d. Nada foi gravado (--dry-run).',
            count($emissionIds),
            count($rows),
        ));

        return self::SUCCESS;
    }

    /**
     * As Emissões pedidas, ou `null` quando alguma não é um id.
     *
     * Valor ilegível não vira "todas as Emissões": quem pediu uma Emissão
     * específica e digitou errado precisa saber, e não receber a verificação
     * da base inteira como se fosse a pedida.
     *
     * @return list<int>|null
     */
    private function requestedEmissionIds(): ?array
    {
        $emissionIds = [];

        foreach ((array) $this->option('emission') as $value) {
            if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1) {
                $this->error(sprintf('Emissão inválida: "%s". Informe o id numérico.', is_scalar($value) ? (string) $value : gettype($value)));

                return null;
            }

            $emissionIds[] = (int) trim($value);
        }

        return array_values(array_unique($emissionIds));
    }

    private function money(string|float|null $value): string
    {
        return $value === null ? 'sem PU no mês' : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }
}
