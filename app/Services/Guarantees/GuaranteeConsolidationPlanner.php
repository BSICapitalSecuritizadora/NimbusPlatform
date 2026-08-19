<?php

namespace App\Services\Guarantees;

use App\Concerns\MoneyFormatter;
use App\DTOs\Guarantees\GuaranteeConsolidationPlan;
use App\DTOs\Guarantees\GuaranteeFieldDelta;
use App\DTOs\Guarantees\GuaranteeMatch;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\GuaranteeType;
use App\Models\ExtractedGuarantee;
use App\Models\Fund;
use App\Models\Guarantee;
use App\Models\GuaranteeDocumentReference;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Diz o que um documento acrescenta a uma garantia já cadastrada (§17 do
 * escopo): o que preenche, o que apenas confirma e o que diverge.
 *
 * O plano é calculado antes de qualquer escrita e é o mesmo objeto que a tela
 * exibe e que o serviço aplica — o impacto mostrado ao revisor não é uma
 * segunda leitura que pode discordar da execução.
 */
class GuaranteeConsolidationPlanner
{
    /**
     * Campos comparáveis da garantia.
     *
     * `divergence: false` marca campo que só complementa: nome e descrição
     * variam legitimamente entre instrumentos — "Reserva de Obras" na planilha
     * e "Fundo de Obras" na CCB — e tratá-los como divergência transformaria
     * toda correspondência boa num falso conflito.
     *
     * @var array<string, array{label: string, type: string, divergence?: bool}>
     */
    private const COMPARABLE_FIELDS = [
        'description' => ['label' => 'Descrição', 'type' => 'text', 'divergence' => false],
        'contracted_value' => ['label' => 'Valor contratado', 'type' => 'money'],
        'documentary_value' => ['label' => 'Valor documental', 'type' => 'money'],
        'requirement_basis' => ['label' => 'Forma do mínimo', 'type' => 'enum'],
        'requirement_value' => ['label' => 'Valor mínimo', 'type' => 'money'],
        'requirement_percentage' => ['label' => 'Percentual mínimo', 'type' => 'percentage'],
        'requirement_base' => ['label' => 'Base de cálculo', 'type' => 'enum'],
        'requirement_multiplier' => ['label' => 'Multiplicador', 'type' => 'number'],
        'requirement_formula' => ['label' => 'Fórmula do mínimo', 'type' => 'text', 'divergence' => false],
        'requirement_conditions' => ['label' => 'Condições', 'type' => 'text', 'divergence' => false],
        'validity_start_date' => ['label' => 'Início da vigência', 'type' => 'date'],
        'validity_end_date' => ['label' => 'Fim da vigência', 'type' => 'date'],
        'evaluation_frequency' => ['label' => 'Periodicidade de apuração', 'type' => 'text', 'divergence' => false],
    ];

    /**
     * Eventos em que o documento declara estar mudando algo já existente.
     *
     * Fora desta lista, um valor diferente do vigente é divergência a apurar,
     * não atualização: uma constituição que discorda do cadastro provavelmente
     * está falando de outra coisa (§3 do escopo).
     *
     * @var list<GuaranteeEventType>
     */
    private const AMENDING_EVENTS = [
        GuaranteeEventType::Amendment,
        GuaranteeEventType::Reinforcement,
        GuaranteeEventType::Substitution,
        GuaranteeEventType::Release,
        GuaranteeEventType::Revaluation,
    ];

    public function __construct(
        private readonly GuaranteeMatcher $matcher,
        private readonly GuaranteeIdentificationNormalizer $normalizer,
    ) {}

    /**
     * Plano de consolidação de uma candidata já registrada.
     *
     * A correspondência é recalculada no momento da revisão: entre a detecção e
     * a confirmação alguém pode ter cadastrado, editado ou complementado a
     * garantia, e aplicar um plano montado sobre o cadastro antigo escreveria
     * por cima de decisão mais recente.
     */
    public function plan(ExtractedGuarantee $candidate, ?Guarantee $guarantee = null): GuaranteeConsolidationPlan
    {
        $proposal = $candidate->toProposalArray();
        $match = null;

        if ($guarantee === null) {
            $match = $this->matcher->match($proposal, $this->existingGuarantees($candidate));
            $guarantee = $match?->suggestsConsolidation() === true ? $match->guarantee : null;
        }

        if ($guarantee === null) {
            return new GuaranteeConsolidationPlan(
                guarantee: null,
                match: $match,
                outcome: GuaranteeReconciliationOutcome::NewGuarantee,
                linkedFund: $this->findMatchingFund($candidate),
            );
        }

        return $this->build($proposal, $guarantee, $match, $candidate);
    }

    /**
     * Plano a partir de uma proposta ainda não persistida — o caminho da
     * detecção, que precisa classificar a candidata antes de gravá-la.
     *
     * @param  array<string, mixed>  $proposal
     */
    public function planForProposal(array $proposal, Guarantee $guarantee, ?GuaranteeMatch $match = null): GuaranteeConsolidationPlan
    {
        return $this->build($proposal, $guarantee, $match, null);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function build(
        array $proposal,
        Guarantee $guarantee,
        ?GuaranteeMatch $match,
        ?ExtractedGuarantee $candidate,
    ): GuaranteeConsolidationPlan {
        $complements = [];
        $confirmations = [];
        $divergences = [];

        foreach (self::COMPARABLE_FIELDS as $field => $config) {
            $delta = $this->compareField($field, $config, $proposal, $guarantee);

            if ($delta === null) {
                continue;
            }

            match ($delta->kind) {
                GuaranteeFieldDelta::KIND_COMPLEMENT => $complements[] = $delta,
                GuaranteeFieldDelta::KIND_CONFIRMATION => $confirmations[] = $delta,
                default => $divergences[] = $delta,
            };
        }

        foreach ($this->compareIdentification($proposal, $guarantee) as $delta) {
            match ($delta->kind) {
                GuaranteeFieldDelta::KIND_COMPLEMENT => $complements[] = $delta,
                GuaranteeFieldDelta::KIND_CONFIRMATION => $confirmations[] = $delta,
                default => $divergences[] = $delta,
            };
        }

        return new GuaranteeConsolidationPlan(
            guarantee: $guarantee,
            match: $match,
            outcome: $this->resolveOutcome($proposal, $guarantee, $complements, $divergences),
            complements: $complements,
            confirmations: $confirmations,
            divergences: $divergences,
            linkedFund: $candidate === null ? null : $this->findMatchingFund($candidate),
            providesFirstDocumentarySource: $guarantee->documentReferences()->count() === 0,
        );
    }

    /**
     * Classificação geral do que o documento representa (§19 do escopo).
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<GuaranteeFieldDelta>  $complements
     * @param  list<GuaranteeFieldDelta>  $divergences
     */
    private function resolveOutcome(
        array $proposal,
        Guarantee $guarantee,
        array $complements,
        array $divergences,
    ): GuaranteeReconciliationOutcome {
        if ($divergences !== []) {
            return $this->documentDeclaresChange($proposal, $guarantee)
                ? GuaranteeReconciliationOutcome::Change
                : GuaranteeReconciliationOutcome::Conflict;
        }

        return $complements !== []
            ? GuaranteeReconciliationOutcome::Complement
            : GuaranteeReconciliationOutcome::Confirmation;
    }

    /**
     * O documento se apresenta como alteração do que já existia?
     *
     * Exige as duas coisas: um evento que declare mudança e precedência sobre a
     * fonte vigente. Um aditamento anterior ao documento que hoje fundamenta a
     * garantia não a atualiza — ele contradiz o que veio depois, e isso é
     * conflito (§35).
     *
     * @param  array<string, mixed>  $proposal
     */
    private function documentDeclaresChange(array $proposal, Guarantee $guarantee): bool
    {
        $eventType = $this->resolveEventType($proposal['event_type'] ?? null);

        if (! in_array($eventType, self::AMENDING_EVENTS, true)) {
            return false;
        }

        $documentDate = $this->toDateString($proposal['document_date'] ?? $proposal['effective_date'] ?? null);

        if ($documentDate === null) {
            return false;
        }

        $latestSource = $guarantee->documentReferences
            ->filter(fn (GuaranteeDocumentReference $reference): bool => $reference->document_date !== null)
            ->sortByDesc(fn (GuaranteeDocumentReference $reference): string => $reference->document_date->toDateString())
            ->first();

        return $latestSource === null || $documentDate >= $latestSource->document_date->toDateString();
    }

    /**
     * @param  array{label: string, type: string, divergence?: bool}  $config
     * @param  array<string, mixed>  $proposal
     */
    private function compareField(string $field, array $config, array $proposal, Guarantee $guarantee): ?GuaranteeFieldDelta
    {
        if (! array_key_exists($field, $proposal)) {
            return null;
        }

        $newValue = $proposal[$field];

        // O documento não falou do campo. Silêncio não é informação, e muito
        // menos apagamento do que já está cadastrado.
        if (blank($newValue)) {
            return null;
        }

        $currentValue = $guarantee->getAttribute($field);
        $type = $config['type'];

        if (blank($currentValue)) {
            return $this->delta($field, $config['label'], $type, null, $newValue, GuaranteeFieldDelta::KIND_COMPLEMENT);
        }

        if ($this->valuesAreEqual($type, $currentValue, $newValue)) {
            return $this->delta($field, $config['label'], $type, $currentValue, $newValue, GuaranteeFieldDelta::KIND_CONFIRMATION);
        }

        if (($config['divergence'] ?? true) === false) {
            return null;
        }

        return $this->delta($field, $config['label'], $type, $currentValue, $newValue, GuaranteeFieldDelta::KIND_DIVERGENCE);
    }

    /**
     * Compara as chaves de identificação — banco, agência, conta, matrícula,
     * cartório — que é onde mora a maior parte do que um instrumento acrescenta.
     *
     * @param  array<string, mixed>  $proposal
     * @return list<GuaranteeFieldDelta>
     */
    private function compareIdentification(array $proposal, Guarantee $guarantee): array
    {
        $type = $this->resolveType($proposal['type'] ?? null) ?? $guarantee->type;

        $candidate = $this->normalizer->normalize(
            is_array($proposal['identification'] ?? null) ? $proposal['identification'] : [],
            $type,
        ) ?? [];

        $existing = $guarantee->identification ?? [];

        $deltas = [];

        foreach ($candidate as $key => $newValue) {
            $key = (string) $key;

            if (blank($newValue) || ! is_scalar($newValue)) {
                continue;
            }

            $label = $this->normalizer->labelFor($key, $type);
            $currentValue = $existing[$key] ?? null;

            if (blank($currentValue)) {
                $deltas[] = new GuaranteeFieldDelta(
                    field: $key,
                    label: $label,
                    currentValue: null,
                    newValue: $newValue,
                    currentDisplay: 'Não informado',
                    newDisplay: (string) $newValue,
                    kind: GuaranteeFieldDelta::KIND_COMPLEMENT,
                    isIdentification: true,
                );

                continue;
            }

            $same = $this->normalizer->canonicalize($key, $currentValue) === $this->normalizer->canonicalize($key, $newValue);

            $deltas[] = new GuaranteeFieldDelta(
                field: $key,
                label: $label,
                currentValue: $currentValue,
                newValue: $newValue,
                currentDisplay: (string) $currentValue,
                newDisplay: (string) $newValue,
                kind: $same ? GuaranteeFieldDelta::KIND_CONFIRMATION : GuaranteeFieldDelta::KIND_DIVERGENCE,
                isIdentification: true,
            );
        }

        return $deltas;
    }

    /**
     * Fundo já cadastrado cuja conta é a mesma que o documento descreve (§16).
     *
     * Achando-o, a garantia passa a apontar para o fundo em vez de carregar uma
     * segunda cópia dos dados bancários, que envelheceria por conta própria.
     */
    private function findMatchingFund(ExtractedGuarantee $candidate): ?Fund
    {
        $identification = $this->normalizer->normalize($candidate->identification, $candidate->type) ?? [];

        $account = $this->normalizer->canonicalize('account', $identification['account'] ?? null);

        if ($account === null || $candidate->emission_id === null) {
            return null;
        }

        $agency = $this->normalizer->canonicalize('agency', $identification['agency'] ?? null);
        $bank = $this->normalizer->canonicalize('bank', $identification['bank'] ?? null);

        return Fund::query()
            ->with('bank')
            ->where('emission_id', $candidate->emission_id)
            ->get()
            ->first(function (Fund $fund) use ($account, $agency, $bank): bool {
                if ($this->normalizer->canonicalize('account', $fund->account) !== $account) {
                    return false;
                }

                // Agência e banco só desqualificam quando os dois lados os
                // informam: um fundo sem agência cadastrada não deixa de ser o
                // dono da conta por isso.
                $fundAgency = $this->normalizer->canonicalize('agency', $fund->agency);

                if ($agency !== null && $fundAgency !== null && $agency !== $fundAgency) {
                    return false;
                }

                $fundBank = $this->normalizer->canonicalize('bank', $fund->bank?->name);

                return $bank === null || $fundBank === null || $bank === $fundBank;
            });
    }

    /**
     * Garantias da emissão elegíveis a receber o complemento.
     *
     * Liberadas e encerradas ficam de fora: uma garantia extinta não é
     * enriquecida por documento novo — se o documento a menciona, ou é outra
     * garantia, ou é caso de revisão jurídica.
     *
     * @return Collection<int, Guarantee>
     */
    private function existingGuarantees(ExtractedGuarantee $candidate): Collection
    {
        return Guarantee::query()
            ->with(['documentReferences', 'fund'])
            ->where('emission_id', $candidate->emission_id)
            ->when($candidate->guarantee_id !== null, fn ($query) => $query->whereKeyNot($candidate->guarantee_id))
            ->get()
            ->reject(fn (Guarantee $guarantee): bool => $guarantee->legal_status?->isClosed() ?? false)
            ->values();
    }

    private function delta(
        string $field,
        string $label,
        string $type,
        mixed $currentValue,
        mixed $newValue,
        string $kind,
    ): GuaranteeFieldDelta {
        return new GuaranteeFieldDelta(
            field: $field,
            label: $label,
            currentValue: $currentValue,
            newValue: $newValue,
            currentDisplay: $currentValue === null ? 'Não informado' : $this->display($type, $currentValue),
            newDisplay: $this->display($type, $newValue),
            kind: $kind,
        );
    }

    private function valuesAreEqual(string $type, mixed $current, mixed $new): bool
    {
        return match ($type) {
            'money', 'percentage', 'number' => $this->numbersAreEqual($current, $new),
            'date' => $this->toDateString($current) === $this->toDateString($new),
            'enum' => $this->enumValue($current) === $this->enumValue($new),
            default => $this->normalizedText($current) === $this->normalizedText($new),
        };
    }

    private function numbersAreEqual(mixed $current, mixed $new): bool
    {
        if (! is_numeric($current) || ! is_numeric($new)) {
            return false;
        }

        return abs((float) $current - (float) $new) < 0.000001;
    }

    private function display(string $type, mixed $value): string
    {
        return match ($type) {
            'money' => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value),
            'percentage' => is_numeric($value)
                ? number_format((float) $value * 100, 2, ',', '.').'%'
                : (string) $value,
            'number' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',') : (string) $value,
            'date' => $this->toDateString($value) === null
                ? '—'
                : Carbon::parse($this->toDateString($value))->format('d/m/Y'),
            'enum' => $value instanceof BackedEnum && method_exists($value, 'label')
                ? $value->label()
                : (string) $this->enumValue($value),
            default => (string) $value,
        };
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function normalizedText(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $value)));
    }

    private function toDateString(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveEventType(mixed $value): GuaranteeEventType
    {
        if ($value instanceof GuaranteeEventType) {
            return $value;
        }

        return GuaranteeEventType::tryFrom((string) $value) ?? GuaranteeEventType::Constitution;
    }

    private function resolveType(mixed $value): ?GuaranteeType
    {
        if ($value instanceof GuaranteeType) {
            return $value;
        }

        return GuaranteeType::tryFrom((string) $value);
    }
}
