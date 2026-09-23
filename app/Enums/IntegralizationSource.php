<?php

namespace App\Enums;

/**
 * Por onde uma integralização foi registrada.
 *
 * Não é coluna: vai para as propriedades do Activitylog (`source`) no evento
 * que a gravação produziu. A linha em si não guarda origem porque a planilha
 * pode atualizar depois uma integralização cadastrada à mão -- a trilha de
 * auditoria mostra as duas coisas, uma coluna só mostraria a última.
 */
enum IntegralizationSource: string
{
    case Manual = 'manual';

    case Spreadsheet = 'spreadsheet';
}
