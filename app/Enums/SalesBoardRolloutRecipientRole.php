<?php

namespace App\Enums;

/**
 * Quem é avisado sobre o quê, numa Emissão automatizada.
 *
 * Dois papéis, e a divisão segue o fluxo real: o operacional acompanha a
 * apuração e o vaivém com a construtora; a Gestão decide. Um papel só faria a
 * pessoa que decide receber alerta de cadastro incompleto, e a que corrige
 * cadastro receber lembrete de decisão pendente -- e a consequência conhecida
 * disso é que os dois param de ler.
 *
 * Isto **não** é mecanismo de autorização. Ser destinatário não concede
 * permissão nenhuma; quem abre a tela continua passando pelas permissões de
 * sempre.
 */
enum SalesBoardRolloutRecipientRole: string
{
    /**
     * Acompanha a apuração e o handoff com a construtora.
     */
    case Operational = 'operacional';

    /**
     * Decide sobre a competência entregue.
     */
    case Management = 'gestao';

    /**
     * O papel que deve receber cada tipo de alerta da automação.
     *
     * O mapa vive aqui, num lugar só. Espalhá-lo pelo motor de lembretes
     * garantiria que, na primeira adição de tipo, algum alerta fosse para o
     * papel errado sem que nada denunciasse.
     */
    public static function forAlert(SalesBoardAutomationAlertType $alert): self
    {
        return match ($alert) {
            SalesBoardAutomationAlertType::GenerationBlocked,
            SalesBoardAutomationAlertType::GenerationFailed,
            SalesBoardAutomationAlertType::ReadyForBuilder,
            SalesBoardAutomationAlertType::BuilderReminder,
            SalesBoardAutomationAlertType::BuilderEscalation => self::Operational,

            SalesBoardAutomationAlertType::ManagementReminder,
            SalesBoardAutomationAlertType::ManagementEscalation => self::Management,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Responsável operacional',
            self::Management => 'Responsável da Gestão',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Operational => 'Recebe avisos de geração bloqueada, falha técnica e da validação pendente com a construtora.',
            self::Management => 'Recebe avisos de competência aguardando análise e decisão da Gestão.',
        };
    }
}
