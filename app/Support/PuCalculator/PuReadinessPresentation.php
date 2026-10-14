<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport as Report;
use App\Domain\PuCalculator\Services\PuOperationalPreflightService as Preflight;

/**
 * Prontidão da emissão e verificação de implantação (preflight) agrupadas para a
 * tela (Fase 8). Só reorganiza o que os serviços da Fase 7 avaliaram: nenhum item
 * vira "aprovado" sem que o serviço o tenha aprovado, o que depende de conferência
 * humana aparece como verificação manual e o que não rodou aparece como "não
 * verificado". Nada aqui é autorização operacional.
 */
final class PuReadinessPresentation
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const WARNING = 'warning';

    public const MANUAL = 'manual';

    public const INFO = 'info';

    public const NOT_VERIFIED = 'not_verified';

    /** @var array<string, string> grupo => título, na ordem da tela */
    private const GROUPS = [
        'global' => 'Ativação global',
        'eligibility' => 'Elegibilidade da emissão',
        'indexer' => 'Indexador',
        'contract' => 'Pré-requisitos contratuais',
        'market_data' => 'Dados de mercado',
        'governance' => 'Governança financeira',
        'monitoring' => 'Monitoramento, alertas e incidentes',
        'infrastructure' => 'Fila, agenda e recuperação',
    ];

    /** @var array<string, string> chave do item de prontidão => grupo */
    private const READINESS_GROUPS = [
        'pu_parameter' => 'eligibility',
        'official_curve' => 'eligibility',
        'operational_activation' => 'global',
        'indexer_certification' => 'indexer',
        'curve_prerequisites' => 'contract',
        'curve_prerequisite_warnings' => 'contract',
        'informed_schedule_effects' => 'contract',
        'realized_curve_horizon' => 'market_data',
        'governance' => 'governance',
    ];

    /**
     * @param  array{items?: list<array{requirement: string, result: string, evidence: string, action: string}>}|null  $preflight  nulo quando a verificação do ambiente não rodou
     * @param  bool  $scheduleReadable  a agenda (routes/console.php) só é carregada no terminal: numa requisição web a
     *                                  preflight não a enxerga, e o item fica "não verificado" em vez de "atenção"
     * @return array{status: string|null, groups: list<array{title: string, items: list<array<string, mixed>>}>}
     */
    public static function build(?Report $report, ?array $preflight, bool $scheduleReadable = true): array
    {
        $groups = array_map(fn (): array => [], self::GROUPS);

        foreach ($report?->checks ?? [] as $check) {
            $groups[self::READINESS_GROUPS[$check['key']] ?? 'eligibility'][] = self::item(
                $check['label'],
                self::readinessResult($check['result']),
                $check['details'] === [] ? self::defaultDetail($check['result']) : implode(' ', $check['details']),
                null,
            );
        }

        foreach ($report?->contractualChecklist ?? [] as $line) {
            $groups['contract'][] = self::item('Conferência contra o Termo', self::MANUAL, $line, 'Coberta pela referência da aprovação documentada.');
        }

        if ($preflight === null) {
            foreach (['monitoring', 'infrastructure', 'governance'] as $group) {
                $groups[$group][] = self::item(
                    'Verificação do ambiente (preflight)',
                    self::NOT_VERIFIED,
                    'Não executada: exige a permissão de monitorar ou de ativar a operação do PU.',
                    null,
                );
            }
        } else {
            foreach ($preflight['items'] ?? [] as $item) {
                if (! $scheduleReadable && str_starts_with($item['requirement'], 'Agenda do PU')) {
                    $groups['infrastructure'][] = self::item(
                        $item['requirement'],
                        self::NOT_VERIFIED,
                        'A agenda só é carregada no terminal: esta tela não consegue lê-la.',
                        'Conferir com `php artisan pu:operations:preflight` ou `php artisan schedule:list` no ambiente.',
                    );

                    continue;
                }

                $groups[self::preflightGroup($item['requirement'])][] = self::item(
                    $item['requirement'],
                    self::preflightResult($item['requirement'], $item['result']),
                    $item['evidence'],
                    $item['action'],
                );
            }
        }

        $ordered = [];

        foreach (self::GROUPS as $key => $title) {
            if ($groups[$key] !== []) {
                $ordered[] = ['title' => $title, 'items' => $groups[$key]];
            }
        }

        return ['status' => $report?->status->label(), 'groups' => $ordered];
    }

    /**
     * @return array{label: string, color: string, icon: string}
     */
    public static function badge(string $result): array
    {
        return match ($result) {
            self::PASSED => ['label' => 'Aprovado', 'color' => 'success', 'icon' => 'heroicon-m-check-circle'],
            self::FAILED => ['label' => 'Bloqueado', 'color' => 'danger', 'icon' => 'heroicon-m-x-circle'],
            self::WARNING => ['label' => 'Atenção / requer decisão', 'color' => 'warning', 'icon' => 'heroicon-m-exclamation-triangle'],
            self::MANUAL => ['label' => 'Verificação manual necessária', 'color' => 'info', 'icon' => 'heroicon-m-hand-raised'],
            self::NOT_VERIFIED => ['label' => 'Não verificado', 'color' => 'gray', 'icon' => 'heroicon-m-question-mark-circle'],
            default => ['label' => 'Informativo', 'color' => 'gray', 'icon' => 'heroicon-m-information-circle'],
        };
    }

    private static function readinessResult(string $result): string
    {
        return match ($result) {
            Report::RESULT_PASS => self::PASSED,
            Report::RESULT_BLOCKED => self::FAILED,
            Report::RESULT_REVIEW => self::WARNING,
            default => self::INFO,
        };
    }

    /**
     * O interruptor desligado, o indexador bloqueado e a contagem de emissões são
     * o estado seguro que o preflight marca "ok": na tela eles são informação, não
     * "aprovado" -- ninguém deve ler "Prefixado: aprovado".
     */
    private static function preflightResult(string $requirement, string $result): string
    {
        if (str_starts_with($requirement, 'Interruptor global')
            || str_starts_with($requirement, 'Certificação do indexador')
            || str_starts_with($requirement, 'Emissões configuradas')) {
            if ($result === Preflight::OK) {
                return self::INFO;
            }
        }

        return match ($result) {
            Preflight::OK => self::PASSED,
            Preflight::BLOCKED => self::FAILED,
            Preflight::ATTENTION => self::WARNING,
            Preflight::MANUAL => self::MANUAL,
            default => self::NOT_VERIFIED,
        };
    }

    private static function preflightGroup(string $requirement): string
    {
        return match (true) {
            str_starts_with($requirement, 'Interruptor global') => 'global',
            str_starts_with($requirement, 'Certificação do indexador') => 'indexer',
            str_starts_with($requirement, 'Emissões configuradas') => 'eligibility',
            str_starts_with($requirement, 'Horário de divulgação') => 'market_data',
            str_starts_with($requirement, 'Autoridades'), str_starts_with($requirement, 'Retenção') => 'governance',
            str_starts_with($requirement, 'Monitor'), str_starts_with($requirement, 'Entrega de alertas'), str_starts_with($requirement, 'Incidentes') => 'monitoring',
            default => 'infrastructure',
        };
    }

    private static function defaultDetail(string $result): string
    {
        return $result === Report::RESULT_PASS ? 'Sem pendência.' : '—';
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $label, string $result, string $details, ?string $action): array
    {
        return ['label' => $label, 'result' => $result, 'badge' => self::badge($result), 'details' => $details, 'action' => $action];
    }
}
