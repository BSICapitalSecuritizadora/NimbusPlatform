<?php

namespace App\Enums;

enum ProjectIndicatorClassification
{
    case Enquadrado;
    case Analisar;
    case Desenquadrado;
    case NaoInformado;

    public function label(): string
    {
        return match ($this) {
            self::Enquadrado => 'Enquadrado',
            self::Analisar => 'Analisar',
            self::Desenquadrado => 'Desenquadrado',
            self::NaoInformado => 'Não informado',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Enquadrado => 'enquadrado',
            self::Analisar => 'analisar',
            self::Desenquadrado => 'desenquadrado',
            self::NaoInformado => 'nao-informado',
        };
    }
}
