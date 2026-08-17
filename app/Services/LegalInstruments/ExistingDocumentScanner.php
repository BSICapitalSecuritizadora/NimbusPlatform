<?php

namespace App\Services\LegalInstruments;

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\LegalInstrumentDocument;
use Illuminate\Support\Collection;

/**
 * Procura, entre os documentos já anexados à emissão, os que parecem pertencer
 * a um instrumento jurídico (§36 do escopo).
 *
 * Existe para que a implantação não exija reupload: as operações já têm
 * "CRI XYZ_CCB.pdf" e "CRI XYZ_1º Aditamento CCB.pdf" no acervo.
 *
 * A heurística é deliberadamente conservadora e trabalha só sobre o **nome do
 * arquivo e o título** — não abre o PDF. Ela nunca cria nada: devolve
 * sugestões que o usuário confirma. Um palpite errado aqui custaria um dossiê
 * montado com o documento errado, que é pior do que não sugerir.
 */
class ExistingDocumentScanner
{
    /**
     * Padrões de tipo de instrumento, do mais específico para o mais genérico.
     * A ordem importa: "aditamento à CCB" tem de casar com CCB, não com o termo.
     *
     * As siglas usam lookaround em vez de `\b` porque nomes de arquivo separam
     * com underscore ("CRI XYZ_AFI.pdf"), e `_` é caractere de palavra — não há
     * fronteira ali, então `\bafi\b` nunca casaria.
     *
     * @var array<string, string>
     */
    private const TYPE_PATTERNS = [
        '/(?<![\p{L}\p{N}])ccb(?![\p{L}\p{N}])|c[ée]dula de cr[ée]dito banc[áa]rio/iu' => LegalInstrumentType::Ccb->value,
        '/(?<![\p{L}\p{N}])cci(?![\p{L}\p{N}])|c[ée]dula de cr[ée]dito imobili[áa]rio/iu' => LegalInstrumentType::Cci->value,
        '/aliena[çc][ãa]o fiduci[áa]ria de quotas|(?<![\p{L}\p{N}])afq(?![\p{L}\p{N}])/iu' => LegalInstrumentType::QuotaFiduciaryAlienation->value,
        '/aliena[çc][ãa]o fiduci[áa]ria|(?<![\p{L}\p{N}])afi(?![\p{L}\p{N}])/iu' => LegalInstrumentType::RealEstateFiduciaryAlienation->value,
        '/cess[ãa]o fiduci[áa]ria/iu' => LegalInstrumentType::FiduciaryAssignment->value,
        '/cess[ãa]o de cr[ée]ditos/iu' => LegalInstrumentType::CreditAssignment->value,
        '/conta vinculada|conta escrow/iu' => LegalInstrumentType::EscrowAccountAgreement->value,
        '/termo de securitiza[çc][ãa]o/iu' => LegalInstrumentType::SecuritizationTerm->value,
    ];

    /**
     * Papéis reconhecíveis pelo nome. Aditamento é tratado à parte porque
     * carrega o número da ordem.
     *
     * @var array<string, string>
     */
    private const ROLE_PATTERNS = [
        '/libera[çc][ãa]o/iu' => LegalInstrumentDocumentRole::Release->value,
        '/substitui[çc][ãa]o/iu' => LegalInstrumentDocumentRole::Substitution->value,
        '/refor[çc]o/iu' => LegalInstrumentDocumentRole::GuaranteeReinforcement->value,
        '/quita[çc][ãa]o/iu' => LegalInstrumentDocumentRole::Discharge->value,
        '/matr[íi]cula|registro|averba[çc][ãa]o/iu' => LegalInstrumentDocumentRole::Registration->value,
        '/laudo|avalia[çc][ãa]o/iu' => LegalInstrumentDocumentRole::Appraisal->value,
    ];

    /**
     * Sugestões agrupadas por tipo de instrumento.
     *
     * @return Collection<string, array{
     *     type: LegalInstrumentType,
     *     documents: Collection<int, array{document: Document, role: LegalInstrumentDocumentRole, sequence: int|null}>
     * }>
     */
    public function scan(Emission $emission): Collection
    {
        $alreadyInDossier = LegalInstrumentDocument::query()
            ->whereIn('legal_instrument_id', $emission->legalInstruments()->select('id'))
            ->pluck('document_id')
            ->all();

        return $emission->guaranteeSourceDocuments()
            ->get()
            ->reject(fn (Document $document): bool => in_array($document->id, $alreadyInDossier, true))
            ->map(fn (Document $document): ?array => $this->classify($document))
            ->filter()
            ->groupBy(fn (array $match): string => $match['type']->value)
            ->map(fn (Collection $matches, string $type): array => [
                'type' => LegalInstrumentType::from($type),
                'documents' => $matches
                    ->map(fn (array $match): array => [
                        'document' => $match['document'],
                        'role' => $match['role'],
                        'sequence' => $match['sequence'],
                    ])
                    ->sortBy(fn (array $entry): int => $entry['sequence'] ?? 0)
                    ->values(),
            ]);
    }

    /**
     * @return array{document: Document, type: LegalInstrumentType, role: LegalInstrumentDocumentRole, sequence: int|null}|null
     */
    private function classify(Document $document): ?array
    {
        $haystack = trim($document->title.' '.($document->file_name ?? ''));

        $type = $this->matchType($haystack);

        if ($type === null) {
            return null;
        }

        [$role, $sequence] = $this->matchRole($haystack);

        return [
            'document' => $document,
            'type' => $type,
            'role' => $role,
            'sequence' => $sequence,
        ];
    }

    private function matchType(string $haystack): ?LegalInstrumentType
    {
        foreach (self::TYPE_PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $haystack) === 1) {
                return LegalInstrumentType::from($type);
            }
        }

        return null;
    }

    /**
     * @return array{0: LegalInstrumentDocumentRole, 1: int|null}
     */
    private function matchRole(string $haystack): array
    {
        // "3º Aditamento", "2o aditamento", "1º Adit."
        if (preg_match('/(\d+)\s*[ºo°]?\s*aditamento/iu', $haystack, $matches) === 1) {
            return [LegalInstrumentDocumentRole::Amendment, (int) $matches[1]];
        }

        if (preg_match('/aditamento/iu', $haystack) === 1) {
            return [LegalInstrumentDocumentRole::Amendment, null];
        }

        foreach (self::ROLE_PATTERNS as $pattern => $role) {
            if (preg_match($pattern, $haystack) === 1) {
                return [LegalInstrumentDocumentRole::from($role), null];
            }
        }

        return [LegalInstrumentDocumentRole::Original, null];
    }
}
