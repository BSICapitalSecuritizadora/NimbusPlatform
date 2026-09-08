<?php

namespace App\Enums;

/**
 * Os avisos que a automação sabe emitir.
 *
 * Sete, e nenhum deles é "o ciclo foi gerado com sucesso": alerta de coisa que
 * deu certo é o caminho mais curto para ninguém mais ler alerta nenhum. Cada
 * tipo aqui corresponde a algo parado esperando uma pessoa.
 */
enum SalesBoardAutomationAlertType: string
{
    case GenerationBlocked = 'geracao_bloqueada';

    case GenerationFailed = 'geracao_falhou';

    case ReadyForBuilder = 'pronto_para_construtora';

    case BuilderReminder = 'lembrete_construtora';

    case BuilderEscalation = 'escalacao_construtora';

    case ManagementReminder = 'lembrete_gestao';

    case ManagementEscalation = 'escalacao_gestao';

    public function label(): string
    {
        return match ($this) {
            self::GenerationBlocked => 'Geração bloqueada pela fonte',
            self::GenerationFailed => 'Falha técnica na geração',
            self::ReadyForBuilder => 'Posição pronta para envio à construtora',
            self::BuilderReminder => 'Validação da construtora pendente',
            self::BuilderEscalation => 'Validação da construtora em atraso',
            self::ManagementReminder => 'Análise da Gestão pendente',
            self::ManagementEscalation => 'Análise da Gestão em atraso',
        };
    }

    public function isEscalation(): bool
    {
        return $this === self::BuilderEscalation
            || $this === self::ManagementEscalation
            || $this === self::GenerationFailed;
    }
}
