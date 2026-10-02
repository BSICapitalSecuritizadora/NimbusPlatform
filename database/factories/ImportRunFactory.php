<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /**
     * The counters describe one confirmed reconciliation, so they are consistent
     * by default: everything analysed is either new, updated or untouched.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ImportRun::TYPE_CONTRACTS,
            'file_name' => 'carteira.xlsx',
            'checksum' => hash('sha256', fake()->unique()->uuid()),
            'user_id' => User::factory(),
            'contract_id' => null,
            'records_analyzed' => 100,
            'records_created' => 10,
            'records_updated' => 5,
            'records_unchanged' => 85,
            'records_critical' => 0,
        ];
    }

    /**
     * A run correlated to an activity batch. The uuid is only ever produced by
     * `LogBatch` in production -- the factory fabricates one so a test can hold
     * two runs apart without going through a real import.
     */
    public function withBatch(?string $batchUuid = null): static
    {
        return $this->state(fn (): array => [
            'batch_uuid' => $batchUuid ?? fake()->uuid(),
        ]);
    }

    public function installments(): static
    {
        return $this->state(fn (): array => [
            'type' => ImportRun::TYPE_CONTRACT_INSTALLMENTS,
            'file_name' => 'parcelas.xlsx',
        ]);
    }

    /**
     * A re-run of a file that was already the registered position.
     */
    public function unchanged(): static
    {
        return $this->state(fn (): array => [
            'records_created' => 0,
            'records_updated' => 0,
            'records_unchanged' => 100,
            'records_critical' => 0,
        ]);
    }

    public function withCriticalUpdates(int $count = 3): static
    {
        return $this->state(fn (): array => [
            'records_updated' => max($count, 1),
            'records_critical' => $count,
        ]);
    }

    /**
     * A units file: it only creates.
     */
    public function units(): static
    {
        return $this->state(fn (): array => [
            'type' => ImportRun::TYPE_CONSTRUCTION_UNITS,
            'file_name' => 'unidades.xlsx',
            'records_updated' => 0,
            'records_unchanged' => 0,
            'records_created' => 100,
        ]);
    }

    /**
     * A values file: what it "creates" are the lines appended to the history.
     */
    public function unitValues(): static
    {
        return $this->state(fn (): array => [
            'type' => ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES,
            'file_name' => 'valores.xlsx',
            'records_updated' => 0,
        ]);
    }

    /**
     * An installments run that also cancelled the open installments the file
     * left out, by explicit decision, with the date and the reason chosen.
     */
    public function withCancelledAbsences(int $count = 2, string $date = '2026-07-05', string $reason = 'Renegociação com novo cronograma.'): static
    {
        return $this->installments()->state(fn (): array => [
            'records_critical' => 0,
            'records_absent' => $count,
            'records_cancelled' => $count,
            'absence_cancellation_date' => $date,
            'absence_cancellation_reason' => $reason,
        ]);
    }
}
