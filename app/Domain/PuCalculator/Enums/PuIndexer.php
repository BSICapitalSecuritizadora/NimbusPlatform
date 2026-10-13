<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuIndexer: string
{
    case Cdi = 'CDI';
    case Prefixed = 'PREFIXED';
    case Ipca = 'IPCA';

    public function label(): string
    {
        return match ($this) {
            self::Cdi => 'CDI + spread',
            self::Prefixed => 'Prefixado',
            self::Ipca => 'IPCA (em preparação)',
        };
    }

    /**
     * Indexadores que dependem de uma série de índices (index_rates) no período.
     */
    public function requiresIndexRates(): bool
    {
        return match ($this) {
            self::Cdi, self::Ipca => true,
            self::Prefixed => false,
        };
    }

    public function usesSpread(): bool
    {
        return $this === self::Cdi;
    }

    public function usesAnnualRate(): bool
    {
        return $this === self::Prefixed;
    }

    /**
     * Indexadores cuja engine pode chegar ao PU oficial: só os certificados
     * ({@see self::certification()}). Implementar a engine não basta.
     */
    public function isHomologated(): bool
    {
        return $this->certification()->allowsOperationalUse();
    }

    /**
     * Certificação financeira do indexador para uso operacional (Fase 7).
     *
     * - CDI: certificado com condições. A engine contratual reproduz, em 6 casas,
     *   as planilhas independentes do sistema de origem (AMANI e CONVIVA dia a
     *   dia; TROUPE na parte realizada) e a comparação do negócio com o sistema
     *   antigo (2026-09-30); as condições de implantação estão em
     *   {@see self::certificationConditions()}.
     * - Prefixado: NÃO certificado. Nenhum gabarito externo ou exemplo contratual
     *   independente existe: os testes conferem a engine contra a mesma fórmula
     *   que ela implementa. A declaração antiga de "homologado" não basta -- só
     *   simula até haver referência independente das convenções (base, dias úteis,
     *   capitalização, pagamentos).
     * - IPCA: NÃO certificado. A engine reproduz o publicado do gabarito RIO BRANCO
     *   e a política de projeção existe, mas a homologação financeira e operacional
     *   (série projetada aprovada, maker/checker) não foi feita.
     */
    public function certification(): PuIndexerCertification
    {
        return match ($this) {
            self::Cdi => PuIndexerCertification::ConditionallyCertified,
            self::Prefixed, self::Ipca => PuIndexerCertification::NotCertified,
        };
    }

    /**
     * O que ainda falta (implantação) ou o que impede a certificação.
     *
     * @return list<string>
     */
    public function certificationConditions(): array
    {
        return match ($this) {
            self::Cdi => [
                'As planilhas de referência (AMANI, CONVIVA, TROUPE) ficam fora do repositório: os testes de gabarito são pulados no CI e a regressão numérica automática não cobre as convenções contratuais.',
                'O horário real de divulgação do CDI no BCB/SGS não foi confirmado: `available_after` (07:00 BRT) e a segunda sincronização (07:30 BRT) são premissas configuráveis.',
                'Prêmio, teto de amortização extraordinária, mora, multa e demais efeitos contratuais são avaliados por emissão (prontidão contratual), não pelo indexador.',
            ],
            self::Prefixed => [
                'Sem gabarito externo ou exemplo contratual independente da engine prefixada (base 252, dias úteis, capitalização, amortização e pagamentos).',
            ],
            self::Ipca => [
                'Homologação financeira e operacional ainda não realizada: série projetada aprovada, convenções de defasagem e aniversário validadas e aprovação maker/checker.',
            ],
        };
    }
}
