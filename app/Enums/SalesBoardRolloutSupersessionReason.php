<?php

namespace App\Enums;

/**
 * Por que uma homologação aprovada deixou de poder sustentar uma ativação.
 *
 * Gravado em `superseded_reason` junto com o status
 * {@see SalesBoardRolloutHomologationStatus::Superseded}. Os três motivos têm
 * remédio igual -- abrir uma homologação nova, revisada de novo --, mas a tela e
 * a trilha precisam dizer qual deles aconteceu: "a fonte mudou" e "uma tentativa
 * nova foi aberta" são fatos diferentes para quem audita.
 *
 * Quadro manual na competência inicial e responsável ausente **não** são
 * motivos de substituição: os dois se corrigem sem nova homologação, e por isso
 * continuam sendo só recusas da ativação.
 */
enum SalesBoardRolloutSupersessionReason: string
{
    case SourceChanged = 'fonte_alterada';

    case ScopeChanged = 'escopo_alterado';

    case NewAttemptOpened = 'nova_homologacao';

    public function label(): string
    {
        return match ($this) {
            self::SourceChanged => 'A fonte mudou depois da aprovação',
            self::ScopeChanged => 'Os empreendimentos da Emissão mudaram depois da aprovação',
            self::NewAttemptOpened => 'Uma nova homologação foi aberta',
        };
    }

    /**
     * O texto longo do aviso: o que mudou e por que a aprovação deixou de valer.
     */
    public function description(): string
    {
        return match ($this) {
            self::SourceChanged => 'Contratos, parcelas, tabelas de preço, políticas, permutas ou a posição legada da competência de comparação não são mais os que a Gestão revisou, e a ativação só pode usar exatamente o retrato aprovado.',
            self::ScopeChanged => 'Um empreendimento entrou na Emissão ou saiu dela. O rollout é por Emissão inteira, e um empreendimento novo não entra sem ser homologado.',
            self::NewAttemptOpened => 'Uma tentativa nova foi aberta para a Emissão, e só a tentativa mais recente pode sustentar uma ativação.',
        };
    }
}
