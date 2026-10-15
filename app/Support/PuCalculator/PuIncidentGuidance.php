<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Enums\PuOperationalConditionType as Type;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuObligationsRelationManager;
use App\Filament\Resources\IndexRates\IndexRateResource;
use App\Models\Emission;
use App\Models\PuOperationalIncident;

/**
 * O que fazer diante de cada condição operacional do PU, e onde (Fase 8).
 *
 * Só orienta: quem decide a condição continua sendo o monitor da Fase 6
 * ({@see PuOperationalHealthService}). Nenhuma
 * frase aqui resolve, reconhece ou muda um fato financeiro.
 */
final class PuIncidentGuidance
{
    public const TARGET_OPERATIONS = 'operations';

    public const TARGET_OBLIGATIONS = 'obligations';

    public const TARGET_CURVE_HISTORY = 'curve_history';

    public const TARGET_INDEX = 'index';

    public const TARGET_NONE = 'none';

    public static function recommendedAction(Type $type): string
    {
        return match ($type) {
            Type::NoOfficialCurve => 'Nada a corrigir: a primeira curva oficial nasce depois da ativação, pela geração e pela homologação maker/checker.',
            Type::CandidateAwaitingReview => 'Revisar a versão candidata (validação contra planilha) e homologá-la ou invalidá-la. Homologar exige outra pessoa (maker/checker).',
            Type::OfficialCurveStale => 'Conferir se a extensão diária rodou depois da divulgação do índice. Se o índice já está gravado, a próxima rotina incorpora; persistindo, verificar o agendador.',
            Type::OfficialCurveMissingIndex => 'Sincronizar o índice exigido. Se a fonte não o publicou, aguardar; se publicou e não entrou, conferir o histórico de sincronização em Índices.',
            Type::OfficialExtensionFailed => 'Abrir a emissão, ler o motivo da falha da extensão e corrigir o pré-requisito (índice, calendário, evento). A extensão é retomada na próxima rotina.',
            Type::ReprocessingRequired => 'O passado da curva oficial mudou (índice corrigido, insumo contratual). Gerar nova versão, validar e homologar por maker/checker. A versão oficial atual não é alterada.',
            Type::NewVersionRequired => 'Há mudança contratual ainda não homologada: gerar nova versão que a incorpore, validar e homologar. A oficial vigente avança só até a véspera da mudança.',
            Type::ContractualChangePending => 'Mudança contratual futura registrada: preparar e homologar a nova versão antes da data de vigência.',
            Type::UnsupportedIndexer, Type::UnsupportedIndexerOfficialCurve => 'O indexador não é certificado para uso oficial: só simulação. Não há ação na tela; a certificação é decisão financeira separada.',
            Type::CurveGenerationFailed => 'Ler o motivo da falha da geração na emissão, corrigir o insumo e gerar de novo (se a operação estiver ativa).',
            Type::CurveGenerationStuck => 'A geração está em processamento além do prazo: conferir o worker da fila antes de gerar outra vez.',
            Type::OperationsNotActivated => 'A operação do PU desta emissão não foi ativada: avaliar a prontidão e, com aprovação documentada, ativar em "Operação do PU".',
            Type::OperationsSuspended => 'A operação do PU está suspensa: tratar o motivo registrado na suspensão e reativar, se autorizado.',
            Type::IndexAwaitingSynchronization => 'A divulgação já era esperada e a sincronização ainda não rodou: aguardar a rotina ou sincronizar manualmente em Índices.',
            Type::IndexSynchronizationOverdue => 'A sincronização está atrasada: conferir o agendador e o histórico de sincronização em Índices.',
            Type::IndexSyncFailed => 'A fonte do índice falhou: ver a categoria da falha no histórico de sincronização e tentar de novo quando a fonte responder.',
            Type::IndexObservationMissing => 'A fonte respondeu sem a observação esperada depois da divulgação: conferir no BCB/SGS e registrar a observação pela importação, se oficial.',
            Type::IndexRateConflict => 'A fonte informa valor diferente do registrado: analisar e, se for o caso, registrar a correção governada da observação (motivo obrigatório).',
            Type::IndexObservationPendingReview => 'A fonte entregou observação que não entrou no índice (salto suspeito, fora do domínio, divergente ou malformada): conferir na fonte e decidir em "Observações retidas para revisão", em Índices. Enquanto pendente, ela não vale como índice realizado.',
            Type::ObligationsUnsettledPastDue => 'Há obrigações vencidas sem liquidação registrada: registrar a liquidação (com comprovante) na aba de obrigações da emissão.',
            Type::SettlementDivergent => 'A liquidação difere do esperado oficial: analisar o comprovante; se o registro estiver errado, corrigir a liquidação com motivo. Divergência real fica registrada.',
            Type::SettlementConflictOpen => 'Um fato de liquidação contradiz o livro: decidir o conflito (aceitar ou rejeitar, com motivo) na aba de obrigações da emissão.',
            Type::ReconciliationIndeterminate => 'Há liquidação registrada, mas o esperado não é confiável agora: resolver o motivo do cálculo (índice, reprocessamento, efeito sem regra).',
            Type::ObligationUnsupportedEffect => 'Um efeito financeiro não tem regra de cálculo: rever o evento contratual. Sem regra, a obrigação fica sem esperado — nunca zero.',
            Type::ObligationReprocessingRequired => 'O esperado está em dúvida porque a curva precisa de reprocessamento: homologar a nova versão resolve.',
            Type::ObligationAwaitingIndex => 'Normal antes da divulgação do índice: o esperado aparece quando a curva oficial chegar à data.',
            Type::ObligationRefreshPending, Type::ObligationRefreshRetrying => 'A atualização das obrigações será tentada de novo automaticamente. Se persistir, conferir o worker.',
            Type::ObligationRefreshStalled => 'A atualização das obrigações parou sem terminar: conferir o worker; a varredura retoma sozinha.',
            Type::ObligationRefreshExhausted => 'As tentativas automáticas acabaram: corrigida a causa técnica, retomar a atualização (motivo obrigatório).',
            Type::ObligationRefreshBlocked => 'Falha permanente (regra de domínio ou governança): corrigir a causa primeiro. Retomar sem corrigir só repete a recusa.',
            Type::QueuedJobsFailed => 'Há jobs do PU com falha na fila: conferir o log do worker. Reenviar jobs não é feito por esta tela.',
        };
    }

    public static function target(Type $type): string
    {
        return match ($type) {
            Type::ObligationsUnsettledPastDue,
            Type::SettlementDivergent,
            Type::SettlementConflictOpen,
            Type::ReconciliationIndeterminate,
            Type::ObligationUnsupportedEffect,
            Type::ObligationReprocessingRequired,
            Type::ObligationAwaitingIndex => self::TARGET_OBLIGATIONS,
            Type::CandidateAwaitingReview,
            Type::CurveGenerationFailed,
            Type::CurveGenerationStuck => self::TARGET_CURVE_HISTORY,
            Type::IndexAwaitingSynchronization,
            Type::IndexSynchronizationOverdue,
            Type::IndexSyncFailed,
            Type::IndexObservationMissing,
            Type::IndexRateConflict,
            Type::IndexObservationPendingReview => self::TARGET_INDEX,
            Type::QueuedJobsFailed => self::TARGET_NONE,
            default => self::TARGET_OPERATIONS,
        };
    }

    /**
     * Onde tratar o incidente, ou nulo quando não há lugar na tela (fila, sistema)
     * ou quando o alvo não existe mais.
     *
     * @return array{label: string, url: string}|null
     */
    public static function link(PuOperationalIncident $incident): ?array
    {
        $target = self::target($incident->type);

        if ($target === self::TARGET_INDEX) {
            return IndexRateResource::canViewAny()
                ? ['label' => 'Abrir Índices', 'url' => IndexRateResource::getUrl('index')]
                : null;
        }

        $emission = $incident->emission;

        if (! $emission instanceof Emission || $target === self::TARGET_NONE) {
            return null;
        }

        return match ($target) {
            self::TARGET_OBLIGATIONS => ['label' => 'Abrir obrigações da emissão', 'url' => self::obligationsUrl($emission)],
            self::TARGET_CURVE_HISTORY => ['label' => 'Abrir histórico da curva', 'url' => EmissionResource::getUrl('pu-history', ['record' => $emission])],
            default => ['label' => 'Abrir operação do PU da emissão', 'url' => EmissionResource::getUrl('pu-operation', ['record' => $emission])],
        };
    }

    /**
     * A aba de obrigações da emissão (o `?relation=` só aceita o índice).
     */
    public static function obligationsUrl(Emission|int $emission): string
    {
        $index = array_search(PuObligationsRelationManager::class, EmissionResource::getRelations(), true);

        return EmissionResource::getUrl('edit', array_filter([
            'record' => $emission,
            'relation' => $index !== false ? (string) $index : null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
