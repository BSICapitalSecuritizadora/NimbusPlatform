<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\B3DiSource;
use App\Domain\PuCalculator\DTOs\BcbSgsBlockFailure;
use App\Domain\PuCalculator\DTOs\BcbSgsFetchResult;
use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\BcbSgsRawPayload;
use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use App\Domain\PuCalculator\DTOs\CdiSourceRecord;
use Carbon\CarbonImmutable;

final class CdiSourceHomologationService
{
    private const BCB_SERIES_CODE = 4389;

    public function __construct(
        private readonly B3DiSource $b3Source,
        private readonly BcbSgsClient $bcbClient,
        private readonly CdiRateNormalizer $normalizer,
        private readonly CdiSourceComparisonService $comparisonService,
    ) {}

    /** @return array<string, mixed> */
    public function execute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $startedAt = CarbonImmutable::now();
        $b3 = $this->b3Source->fetch($from, $to);
        $bcbFetch = $this->bcbClient->fetchSeries(self::BCB_SERIES_CODE, $from, $to);
        $bcb = $this->toBcbDataset($bcbFetch, $from, $to);
        $comparisonTo = $this->commonCutoff($b3, $bcb);

        if ($comparisonTo === null || $comparisonTo->lessThan($from)) {
            return $this->inconclusiveReport($startedAt, $from, $to, $b3, $bcb);
        }

        $comparison = $this->comparisonService->compare($b3, $bcb, $from, $comparisonTo);
        $classification = $this->classification($comparison->summary, $b3, $bcb);
        $report = [
            'phase' => '2B.5.4 — Homologação da Fonte da Taxa DI',
            'workflow_status' => 'ready_for_review',
            'approved' => false,
            'classification' => $classification,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => CarbonImmutable::now()->toIso8601String(),
            'requested_period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'compared_period' => [
                'from' => $from->toDateString(),
                'to' => $comparisonTo->toDateString(),
                'rule' => 'última data disponível simultaneamente nas duas fontes',
            ],
            'normalization' => [
                'b3' => 'Exatamente 9 dígitos; duas casas decimais implícitas conforme documentação B3 (ex.: 000002320 → 23.20).',
                'bcb' => 'Separador decimal vírgula ou ponto normalizado para ponto; máximo de 2 casas, sem arredondamento.',
                'comparison' => 'Comparação decimal exata na escala oficial de 2 casas; tolerância zero.',
            ],
            'methodological_evidence' => [
                'b3_indicator' => 'Taxa DI apurada e divulgada pela B3, expressa ao ano, base 252 Dias Úteis, com duas casas decimais.',
                'bcb_indicator' => 'SGS 4389 — Taxa de juros - CDI anualizada base 252, frequência diária, unidade % a.a.',
                'compatibility_assessment' => 'Natureza, unidade, periodicidade e precisão publicada são compatíveis; a prova operacional depende também da igualdade integral data a data.',
                'b3_documentation_url' => config('pu_indexes.b3_di.documentation_url'),
                'b3_methodology_url' => config('pu_indexes.b3_di.methodology_url'),
                'bcb_series_url' => 'https://www3.bcb.gov.br/sgspub/consultarvalores/consultarValoresSeries.do?hdOidSeriesSelecionadas=4389&method=consultarGraficoPorId',
                'bcb_api_url' => sprintf('%s/bcdata.sgs.%d/dados', rtrim((string) config('pu_indexes.bcb.base_url'), '/'), self::BCB_SERIES_CODE),
            ],
            'sources' => [
                'b3' => $this->sourceSummary($b3, $from, $comparisonTo),
                'bcb' => $this->sourceSummary($bcb, $from, $comparisonTo),
            ],
            'comparison' => $comparison->toArray(),
            'critical_dates' => $this->criticalDates($comparison->rows, $from, $comparisonTo),
            'revision_observation' => 'As fontes podem republicar/corrigir dados. Cada execução preserva payload, URL, captura e SHA-256; uso operacional futuro deve manter esse vínculo de revisão.',
            'side_effect_policy' => [
                'database_writes' => false,
                'index_rates' => false,
                'pu_histories' => false,
                'payments' => false,
                'pu_curves' => false,
                'emission_parameters' => false,
            ],
            'operational_recommendation' => $this->recommendation($classification),
        ];
        $report['report_checksum'] = hash('sha256', $this->encode($report));

        return $report;
    }

    private function toBcbDataset(BcbSgsFetchResult $fetch, CarbonImmutable $from, CarbonImmutable $to): CdiSourceDataset
    {
        $payloads = array_map(fn (BcbSgsRawPayload $payload): array => $payload->toArray(), $fetch->rawPayloads);
        $records = array_map(function (BcbSgsRateData $rate) use ($fetch): CdiSourceRecord {
            $rawValue = $rate->rawValue ?? $rate->value;
            $normalized = $this->normalizer->fromPublishedDecimal($rawValue, 2);
            $payload = collect($fetch->rawPayloads)->first(
                fn (BcbSgsRawPayload $candidate): bool => $rate->referenceDate->betweenIncluded($candidate->from, $candidate->to),
            );

            return new CdiSourceRecord(
                source: 'bcb_sgs_4389',
                referenceDate: $rate->referenceDate,
                sourceReference: sprintf('bcb_sgs:4389:%s', $rate->referenceDate->toDateString()),
                rawValue: $rawValue,
                normalizedValue: $normalized['value'],
                issue: $normalized['issue'],
                payloadSha256: $payload?->sha256,
            );
        }, $fetch->rates);
        $issues = [
            ...$fetch->invalidEntries,
            ...$fetch->duplicateDates,
            ...array_map(fn (BcbSgsBlockFailure $failure): array => [
                'issue' => 'block_failure',
                'from' => $failure->from->toDateString(),
                'to' => $failure->to->toDateString(),
                'message' => $failure->message,
            ], $fetch->blockFailures),
        ];

        foreach ($records as $record) {
            if ($record->issue !== null) {
                $issues[] = $record->toArray();
            }
        }

        return new CdiSourceDataset(
            source: 'bcb_sgs_4389',
            requestedFrom: $from,
            requestedTo: $to,
            capturedAt: CarbonImmutable::now(),
            records: $records,
            payloads: $payloads,
            issues: $issues,
            metadata: [
                'series_code' => self::BCB_SERIES_CODE,
                'description' => 'Taxa de juros - CDI anualizada base 252',
                'frequency' => 'diária',
                'unit' => '% a.a.',
                'published_scale' => 2,
                'format' => 'JSON: [{"data":"dd/MM/aaaa","valor":"decimal"}]',
                'blocks_total' => $fetch->blocksTotal,
                'blocks_failed' => $fetch->blocksFailed(),
                'capture_method' => 'API pública oficial SGS do Banco Central',
            ],
        );
    }

    private function commonCutoff(CdiSourceDataset $b3, CdiSourceDataset $bcb): ?CarbonImmutable
    {
        $lastB3 = $b3->lastAvailableDate();
        $lastBcb = $bcb->lastAvailableDate();

        if ($lastB3 === null || $lastBcb === null) {
            return null;
        }

        return $lastB3->lessThan($lastBcb) ? $lastB3 : $lastBcb;
    }

    /** @param array<string, mixed> $summary */
    private function classification(array $summary, CdiSourceDataset $b3, CdiSourceDataset $bcb): string
    {
        if ($summary['present_different'] > 0) {
            return 'C — Não equivalentes';
        }

        if ($summary['only_b3'] > 0
            || $summary['only_bcb'] > 0
            || $summary['invalid_value'] > 0
            || $summary['duplicate_date'] > 0
            || $b3->issues !== []
            || $bcb->issues !== []
            || $summary['common_dates'] < (int) config('pu_indexes.source_homologation.minimum_common_records', 250)) {
            return 'D — Inconclusivo';
        }

        return 'B — Equivalência comprovada com transformação';
    }

    /** @return array<string, mixed> */
    private function sourceSummary(CdiSourceDataset $dataset, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'metadata' => $dataset->metadata,
            'available_from' => $dataset->firstAvailableDate()?->toDateString(),
            'available_to' => $dataset->lastAvailableDate()?->toDateString(),
            'captured_records' => count($dataset->records),
            'compared_records' => $dataset->countBetween($from, $to),
            'normalized_checksum' => $dataset->normalizedChecksum($from, $to),
            'raw_payload_manifest_checksum' => $dataset->rawPayloadChecksum(),
            'issues' => $dataset->issues,
            'payloads' => $dataset->payloads,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function criticalDates(array $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rowsByDate = collect($rows)->keyBy('date');
        $dates = [
            '2025-01-01', '2025-01-02', '2025-02-28', '2025-03-01',
            '2025-03-03', '2025-03-04', '2025-03-05', '2025-04-18',
            '2025-06-19', '2025-12-24', '2025-12-25', '2025-12-31',
            '2026-01-01', '2026-01-02', '2026-02-16', '2026-02-17',
            '2026-02-18', '2026-04-03', '2026-06-04', '2026-07-31',
            '2026-08-01',
        ];

        return collect($dates)
            ->filter(fn (string $date): bool => CarbonImmutable::parse($date)->betweenIncluded($from, $to))
            ->map(function (string $date) use ($rowsByDate): array {
                $row = $rowsByDate->get($date);

                return $row ?? [
                    'date' => $date,
                    'status' => 'absent_from_both',
                    'observation' => 'Ausência observada; não classificada como feriado ou dia não útil por esta homologação.',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function recommendation(string $classification): array
    {
        if (! str_starts_with($classification, 'B')) {
            return [
                'eligible_for_operational_use' => false,
                'action' => 'Não usar SGS 4389 como Taxa DI B3 até resolver as divergências ou lacunas do dossiê.',
            ];
        }

        return [
            'eligible_for_operational_use_after_approval' => true,
            'source' => 'bcb_sgs',
            'source_reference' => 'bcb_sgs:4389',
            'external_series_code' => '4389',
            'transformation' => 'Normalizar o separador decimal sem arredondar; a codificação B3 de nove dígitos é decodificada com duas casas implícitas apenas na homologação.',
            'fingerprint' => 'SHA-256 do payload bruto e do manifesto normalizado por data.',
            'metadata' => ['unit' => '% a.a.', 'basis' => 252, 'published_scale' => 2],
            'synchronization' => 'Consulta diária idempotente; preservar data de captura, payload/checksum e não preencher datas ausentes.',
            'unavailability' => 'Falhar explicitamente e manter a data sem snapshot; sem carry-forward, interpolação ou projeção.',
            'audit' => 'Vincular cada lote operacional à revisão deste dossiê e registrar eventual republicação.',
        ];
    }

    /** @return array<string, mixed> */
    private function inconclusiveReport(
        CarbonImmutable $startedAt,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CdiSourceDataset $b3,
        CdiSourceDataset $bcb,
    ): array {
        $report = [
            'phase' => '2B.5.4 — Homologação da Fonte da Taxa DI',
            'workflow_status' => 'ready_for_review',
            'approved' => false,
            'classification' => 'D — Inconclusivo',
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => CarbonImmutable::now()->toIso8601String(),
            'requested_period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'reason' => 'Uma ou ambas as fontes não retornaram datas que permitissem estabelecer período comum.',
            'sources' => [
                'b3' => $b3->toArray(),
                'bcb' => $bcb->toArray(),
            ],
            'side_effect_policy' => ['database_writes' => false],
        ];
        $report['report_checksum'] = hash('sha256', $this->encode($report));

        return $report;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
