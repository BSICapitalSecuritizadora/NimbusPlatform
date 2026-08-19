<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\GuaranteeMatch;
use App\Enums\GuaranteeMatchLevel;
use App\Enums\GuaranteeType;
use App\Models\Guarantee;
use Illuminate\Support\Collection;

/**
 * Procura, entre as garantias já cadastradas, aquela que a candidata
 * provavelmente é (§6 do escopo).
 *
 * O nome sozinho não decide nada: "Reserva de Obras" e "Fundo de Obras" são a
 * mesma garantia com nomenclaturas diferentes, enquanto "Fundo de Reserva" e
 * "Fundo de Obras" são patrimônios distintos com nomes parecidos. O que
 * discrimina é a finalidade econômica somada aos identificadores objetivos —
 * conta, matrícula, CNPJ, empreendimento, instrumento.
 *
 * Identificador forte divergente é veto, não desconto: duas garantias com
 * matrículas diferentes não são a mesma por mais que tudo o mais coincida.
 */
class GuaranteeMatcher
{
    /**
     * Identificadores que, coincidindo, praticamente decidem a correspondência
     * — e que, divergindo, a impedem.
     *
     * @var array<string, string>
     */
    private const STRONG_IDENTITY_KEYS = [
        'registration_number' => 'Mesma matrícula',
        'account' => 'Mesma conta bancária',
        'tax_id' => 'Mesmo CNPJ/CPF',
        'policy_number' => 'Mesma apólice',
        'portfolio' => 'Mesma carteira',
    ];

    /**
     * Identificadores que reforçam a hipótese sem decidi-la sozinhos.
     *
     * @var array<string, string>
     */
    private const SUPPORTING_IDENTITY_KEYS = [
        'bank' => 'Mesmo banco',
        'agency' => 'Mesma agência',
        'registry_office' => 'Mesmo cartório',
        'company' => 'Mesma empresa',
        'construction' => 'Mesmo empreendimento',
        'grantor' => 'Mesmo fiduciante',
        'guarantor' => 'Mesmo garantidor',
        'issuer' => 'Mesmo emissor',
        'fund_type' => 'Mesmo tipo de fundo',
    ];

    /**
     * Finalidades econômicas de fundos e contas vinculadas, da mais específica
     * para a mais genérica.
     *
     * A ordem é o que separa "Reserva de Obras" de "Fundo de Reserva": as duas
     * contêm "reserva", mas só a primeira contém "obras", e obras é a
     * finalidade mais específica das duas.
     *
     * @var array<string, list<string>>
     */
    private const ECONOMIC_PURPOSES = [
        'obras' => ['obras', 'obra', 'construcao'],
        'juros' => ['juros', 'servico da divida'],
        'despesas' => ['despesas', 'despesa'],
        'liquidez' => ['liquidez'],
        'reserva' => ['reserva'],
    ];

    public function __construct(
        private readonly GuaranteeIdentificationNormalizer $normalizer,
    ) {}

    /**
     * Melhor correspondência para a candidata, ou null quando nada se sustenta.
     *
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, Guarantee>  $existingGuarantees
     */
    public function match(array $proposal, Collection $existingGuarantees): ?GuaranteeMatch
    {
        $matches = $this->rank($proposal, $existingGuarantees);

        $best = $matches[0] ?? null;

        if ($best === null) {
            return null;
        }

        // Duas candidatas igualmente plausíveis não são uma correspondência:
        // são duas garantias parecidas, e escolher entre elas é do revisor.
        $runnerUp = $matches[1] ?? null;

        if ($runnerUp !== null && abs($best->score - $runnerUp->score) < 0.1) {
            return new GuaranteeMatch(
                guarantee: $best->guarantee,
                score: min($best->score, 0.5),
                level: GuaranteeMatchLevel::Low,
                evidence: $best->evidence,
                contradictions: array_merge($best->contradictions, [
                    sprintf('Outra garantia cadastrada ("%s") corresponde igualmente bem.', $runnerUp->guarantee->display_name),
                ]),
            );
        }

        return $best;
    }

    /**
     * Todas as correspondências plausíveis, da mais forte para a mais fraca.
     *
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, Guarantee>  $existingGuarantees
     * @return list<GuaranteeMatch>
     */
    public function rank(array $proposal, Collection $existingGuarantees): array
    {
        $matches = [];

        foreach ($existingGuarantees as $guarantee) {
            $match = $this->score($proposal, $guarantee);

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        usort($matches, static fn (GuaranteeMatch $a, GuaranteeMatch $b): int => $b->score <=> $a->score);

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function score(array $proposal, Guarantee $guarantee): ?GuaranteeMatch
    {
        $candidateType = $this->resolveType($proposal['type'] ?? null);

        $candidateIdentification = $this->normalizer->normalize(
            is_array($proposal['identification'] ?? null) ? $proposal['identification'] : [],
            $candidateType,
        ) ?? [];

        $existingIdentification = $guarantee->identification ?? [];

        $evidence = [];
        $contradictions = [];
        $score = 0.0;

        $strong = $this->compareIdentityKeys(
            self::STRONG_IDENTITY_KEYS,
            $candidateIdentification,
            $existingIdentification,
        );

        // Identificador forte divergente encerra a análise: o resto da
        // coincidência não sobrevive a duas matrículas diferentes.
        if ($strong['conflicts'] !== []) {
            return null;
        }

        $score += min(0.6, 0.45 * count($strong['matches']));
        $evidence = array_merge($evidence, $strong['matches']);

        $typeScore = $this->scoreType($candidateType, $guarantee->type, $evidence, $contradictions);

        if ($typeScore === null) {
            return null;
        }

        $score += $typeScore;

        $purposeScore = $this->scorePurpose($proposal, $guarantee, $candidateType, $evidence);

        if ($purposeScore === null) {
            return null;
        }

        $score += $purposeScore;

        $supporting = $this->compareIdentityKeys(
            self::SUPPORTING_IDENTITY_KEYS,
            $candidateIdentification,
            $existingIdentification,
        );

        $score += min(0.25, 0.1 * count($supporting['matches']));
        $evidence = array_merge($evidence, $supporting['matches']);
        $contradictions = array_merge($contradictions, $supporting['conflicts']);
        $score -= min(0.3, 0.15 * count($supporting['conflicts']));

        if ($this->sharesInstrument($proposal, $guarantee)) {
            $score += 0.1;
            $evidence[] = 'Mesmo instrumento jurídico';
        }

        // Ausência de contradição é evidência por si só quando já há mais de um
        // sinal convergente: é o que separa "parecidas" de "a mesma".
        if ($contradictions === [] && count($evidence) >= 2) {
            $score += 0.1;
            $evidence[] = 'Nenhuma informação contraditória entre as duas';
        }

        // Arredonda antes de classificar: somas como 0,4 + 0,3 + 0,1 caem em
        // 0,7999… e derrubariam para "média" uma correspondência que os pesos
        // definem como alta.
        $score = round(max(0.0, min(1.0, $score)), 4);

        if ($score <= 0.0) {
            return null;
        }

        return new GuaranteeMatch(
            guarantee: $guarantee,
            score: $score,
            level: GuaranteeMatchLevel::fromScore($score),
            evidence: array_values(array_unique($evidence)),
            contradictions: array_values(array_unique($contradictions)),
        );
    }

    /**
     * Peso do tipo. Retorna null quando os tipos pertencem a famílias
     * econômicas distintas — uma hipoteca não é um fundo de reserva.
     *
     * @param  list<string>  $evidence
     * @param  list<string>  $contradictions
     */
    private function scoreType(
        ?GuaranteeType $candidateType,
        ?GuaranteeType $existingType,
        array &$evidence,
        array &$contradictions,
    ): ?float {
        if ($candidateType === null || $existingType === null) {
            // Garantia sem classificação é a herdada de antes do módulo. Ela
            // continua elegível: negar correspondência a ela obrigaria a
            // recadastrar tudo o que existia antes.
            $contradictions[] = 'Uma das garantias ainda não tem o tipo classificado.';

            return 0.05;
        }

        if ($candidateType === $existingType) {
            $evidence[] = sprintf('Mesmo tipo de garantia: %s', $existingType->label());

            return 0.4;
        }

        if ($candidateType->category() === $existingType->category()) {
            $evidence[] = sprintf(
                'Mesma família econômica: %s (%s / %s)',
                $existingType->category()->label(),
                $candidateType->shortLabel(),
                $existingType->shortLabel(),
            );

            return 0.25;
        }

        return null;
    }

    /**
     * Peso da finalidade econômica lida do nome.
     *
     * Retorna null quando as finalidades são reconhecidas e diferentes: fundo
     * de obras e fundo de juros são patrimônios distintos ainda que o tipo e a
     * emissão coincidam.
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  $evidence
     */
    private function scorePurpose(
        array $proposal,
        Guarantee $guarantee,
        ?GuaranteeType $candidateType,
        array &$evidence,
    ): ?float {
        $candidatePurpose = $this->economicPurpose((string) ($proposal['name'] ?? ''), $candidateType);
        $existingPurpose = $this->economicPurpose($guarantee->display_name, $guarantee->type);

        if ($candidatePurpose !== null && $existingPurpose !== null) {
            if ($candidatePurpose !== $existingPurpose) {
                return null;
            }

            $evidence[] = sprintf('Mesma finalidade econômica: %s', ucfirst($candidatePurpose));

            return 0.3;
        }

        return $this->namesConverge((string) ($proposal['name'] ?? ''), $guarantee->display_name, $evidence);
    }

    /**
     * Convergência textual entre nomes, usada quando não há finalidade
     * reconhecida. Só palavras com significado contam: "garantia", "contrato" e
     * afins aproximariam qualquer par.
     *
     * @param  list<string>  $evidence
     */
    private function namesConverge(string $candidateName, string $existingName, array &$evidence): float
    {
        $candidateTokens = $this->significantTokens($candidateName);
        $existingTokens = $this->significantTokens($existingName);

        if ($candidateTokens === [] || $existingTokens === []) {
            return 0.0;
        }

        $shared = array_intersect($candidateTokens, $existingTokens);

        if ($shared === []) {
            return 0.0;
        }

        $ratio = count($shared) / min(count($candidateTokens), count($existingTokens));

        $evidence[] = sprintf('Nomes convergentes: "%s" e "%s"', $candidateName, $existingName);

        return round(0.2 * $ratio, 4);
    }

    /**
     * Compara um conjunto de chaves de identificação, separando o que coincide
     * do que se contradiz. Chave ausente de um dos lados não é nem uma coisa
     * nem outra — é justamente o que o complemento vai preencher.
     *
     * @param  array<string, string>  $keys
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $existing
     * @return array{matches: list<string>, conflicts: list<string>}
     */
    private function compareIdentityKeys(array $keys, array $candidate, array $existing): array
    {
        $matches = [];
        $conflicts = [];

        foreach ($keys as $key => $label) {
            $candidateValue = $this->normalizer->canonicalize($key, $candidate[$key] ?? null);
            $existingValue = $this->normalizer->canonicalize($key, $existing[$key] ?? null);

            if ($candidateValue === null || $existingValue === null) {
                continue;
            }

            if ($candidateValue === $existingValue) {
                $matches[] = $label;

                continue;
            }

            $conflicts[] = sprintf('%s divergente: %s ≠ %s', $label, $existing[$key], $candidate[$key]);
        }

        return ['matches' => $matches, 'conflicts' => $conflicts];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function sharesInstrument(array $proposal, Guarantee $guarantee): bool
    {
        $instrumentId = $proposal['legal_instrument_id'] ?? null;

        return $instrumentId !== null
            && $guarantee->legal_instrument_id !== null
            && (int) $instrumentId === (int) $guarantee->legal_instrument_id;
    }

    /**
     * Finalidade econômica de um fundo ou conta vinculada, lida do nome e, na
     * falta dele, do rótulo do tipo.
     */
    private function economicPurpose(string $name, ?GuaranteeType $type): ?string
    {
        $haystack = $this->asciiLower($name.' '.($type?->label() ?? ''));

        foreach (self::ECONOMIC_PURPOSES as $purpose => $terms) {
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    return $purpose;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $value): array
    {
        $stopWords = [
            'de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'as', 'os', 'em', 'no', 'na',
            'garantia', 'garantias', 'contrato', 'instrumento', 'termo', 'clausula',
        ];

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $this->asciiLower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) > 2 && ! in_array($token, $stopWords, true),
        )));
    }

    private function asciiLower(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower($transliterated === false ? $value : $transliterated);
    }

    private function resolveType(mixed $value): ?GuaranteeType
    {
        if ($value instanceof GuaranteeType) {
            return $value;
        }

        return GuaranteeType::tryFrom((string) $value);
    }
}
