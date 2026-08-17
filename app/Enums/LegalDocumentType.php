<?php

namespace App\Enums;

/**
 * Natureza jurídica de um documento vinculado a uma emissão (§3 e §35 do escopo).
 *
 * A categoria de `documents` é voltada ao portal do investidor e não distingue
 * um Termo de Securitização de um aditamento. Sem essa distinção não há como
 * ordenar a cadeia documental nem decidir qual instrumento prevalece, então a
 * classificação vive no vínculo emissão↔documento.
 */
enum LegalDocumentType: string
{
    case SecuritizationTerm = 'termo_securitizacao';
    case TermAmendment = 'aditamento_termo';
    case RealEstateFiduciaryAlienation = 'afi';
    case RealEstateFiduciaryAlienationAmendment = 'aditamento_afi';
    case QuotaFiduciaryAlienation = 'afq';
    case QuotaFiduciaryAlienationAmendment = 'aditamento_afq';
    case FiduciaryAssignment = 'cessao_fiduciaria';
    case FiduciaryAssignmentPromise = 'promessa_cessao_fiduciaria';
    case CreditAssignment = 'cessao_creditos';
    case GuaranteeInstrument = 'instrumento_garantia';
    case EscrowAccountAgreement = 'contrato_conta_vinculada';
    case FundDocument = 'documento_fundo';
    case GuaranteeReinforcement = 'reforco_garantia';
    case GuaranteeSubstitution = 'substituicao_garantia';
    case GuaranteeRelease = 'liberacao_garantia';
    case AppraisalReport = 'laudo_avaliacao';
    case PropertyRecord = 'matricula';
    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::SecuritizationTerm => 'Termo de Securitização',
            self::TermAmendment => 'Aditamento ao Termo',
            self::RealEstateFiduciaryAlienation => 'Contrato de Alienação Fiduciária de Imóvel',
            self::RealEstateFiduciaryAlienationAmendment => 'Aditamento de AFI',
            self::QuotaFiduciaryAlienation => 'Alienação Fiduciária de Quotas',
            self::QuotaFiduciaryAlienationAmendment => 'Aditamento de AFQ',
            self::FiduciaryAssignment => 'Contrato de Cessão Fiduciária',
            self::FiduciaryAssignmentPromise => 'Promessa de Cessão Fiduciária',
            self::CreditAssignment => 'Contrato de Cessão de Créditos',
            self::GuaranteeInstrument => 'Instrumento de garantia',
            self::EscrowAccountAgreement => 'Contrato de conta vinculada',
            self::FundDocument => 'Documento relacionado a fundos',
            self::GuaranteeReinforcement => 'Instrumento de reforço de garantia',
            self::GuaranteeSubstitution => 'Instrumento de substituição de garantia',
            self::GuaranteeRelease => 'Instrumento de liberação de garantia',
            self::AppraisalReport => 'Laudo de avaliação',
            self::PropertyRecord => 'Matrícula',
            self::Other => 'Outro documento da operação',
        };
    }

    /**
     * O documento pode constituir garantias por si só, ou apenas alterar as já
     * constituídas? Aditamentos e instrumentos de liberação não criam garantia
     * do nada — se a extração propuser uma constituição a partir deles, ela vai
     * para revisão marcada como possível conflito.
     */
    public function canConstituteGuarantees(): bool
    {
        return match ($this) {
            self::TermAmendment,
            self::RealEstateFiduciaryAlienationAmendment,
            self::QuotaFiduciaryAlienationAmendment,
            self::GuaranteeRelease,
            self::AppraisalReport,
            self::PropertyRecord => false,
            default => true,
        };
    }

    public function isAmendment(): bool
    {
        return match ($this) {
            self::TermAmendment,
            self::RealEstateFiduciaryAlienationAmendment,
            self::QuotaFiduciaryAlienationAmendment,
            self::GuaranteeReinforcement,
            self::GuaranteeSubstitution,
            self::GuaranteeRelease => true,
            default => false,
        };
    }

    /**
     * Peso na disputa entre documentos de mesma data (§35 do escopo).
     *
     * Um instrumento específico de garantia descreve a garantia com mais
     * precisão do que o Termo, que a menciona em cláusula genérica. Data ainda
     * vem primeiro; isto só desempata.
     */
    public function specificityWeight(): int
    {
        return match ($this) {
            self::GuaranteeRelease,
            self::GuaranteeSubstitution,
            self::GuaranteeReinforcement => 40,
            self::RealEstateFiduciaryAlienation,
            self::QuotaFiduciaryAlienation,
            self::FiduciaryAssignment,
            self::CreditAssignment,
            self::GuaranteeInstrument,
            self::EscrowAccountAgreement => 30,
            self::RealEstateFiduciaryAlienationAmendment,
            self::QuotaFiduciaryAlienationAmendment,
            self::TermAmendment => 20,
            self::SecuritizationTerm => 10,
            default => 0,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
