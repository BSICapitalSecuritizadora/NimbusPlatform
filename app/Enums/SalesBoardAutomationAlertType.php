<?php

namespace App\Enums;

/**
 * Os avisos que a automação sabe emitir.
 *
 * Nenhum deles é "o ciclo foi gerado com sucesso": alerta de coisa que deu certo
 * é o caminho mais curto para ninguém mais ler alerta nenhum. Cada tipo aqui
 * corresponde a algo parado esperando uma pessoa.
 *
 * Os sete primeiros são lembretes de prazo e só saem com o limiar configurado.
 * Os dois últimos não têm prazo a decidir: a execução que morreu no meio e a
 * Emissão cuja automação ficou suspensa por mudança de escopo são avisados na
 * primeira vez em que a automação os encontra -- esperar um SLA para contar que
 * o motor parou seria o mesmo silêncio com mais passos.
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

    case RunInterrupted = 'execucao_interrompida';

    case ScopeSuspended = 'automacao_suspensa';

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
            self::RunInterrupted => 'Execução da automação interrompida',
            self::ScopeSuspended => 'Automação suspensa por mudança de escopo',
        };
    }

    public function isEscalation(): bool
    {
        return $this === self::BuilderEscalation
            || $this === self::ManagementEscalation
            || $this === self::GenerationFailed;
    }
}
