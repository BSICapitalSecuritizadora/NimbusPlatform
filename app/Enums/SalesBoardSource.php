<?php

namespace App\Enums;

use App\Services\SalesBoards\SalesBoardPositionReader;

/**
 * Qual workflow produz os **próximos** quadros mensais de uma Emissão.
 *
 * A leitura importa mais que o nome. `sales_board_source` **não** diz de onde o
 * {@see SalesBoardPositionReader} lê -- ele continua
 * lendo `sales_boards`, sempre, para as duas fontes. Um `if` no leitor
 * ("legacy → SalesBoard, automated → Cycle") criaria duas respostas para a mesma
 * pergunta e desfaria em uma linha o que a Fase 0 levou uma fase inteira para
 * unificar.
 *
 * O que muda é quem escreve: no modo legado, uma pessoa digita a posição; no
 * automatizado, ela sai do ciclo mensal e é publicada pela governança das Fases
 * D/E. Os dois terminam no mesmo `sales_boards`, e é essa fronteira de
 * compatibilidade que mantém Garantias e Relatório sem saber que o rollout
 * existe.
 */
enum SalesBoardSource: string
{
    case Legacy = 'legacy';

    case Automated = 'automated';

    public function isAutomated(): bool
    {
        return $this === self::Automated;
    }

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Legado',
            self::Automated => 'Automatizado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Legacy => 'gray',
            self::Automated => 'success',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Legacy => 'A posição mensal é registrada manualmente, como sempre foi.',
            self::Automated => 'O ciclo mensal é apurado pelo Nimbus e publicado depois da validação da construtora e da análise da Gestão.',
        };
    }
}
