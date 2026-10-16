<?php

namespace App\Services;

use App\Concerns\ScansUploadedFile;
use App\DTOs\Measurements\MeasurementAmountDue;
use App\DTOs\Measurements\MeasurementAmountDueLine;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\DTOs\Measurements\MeasurementFinancialRuleCoverage;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementReconciliationStatus;
use App\Enums\MeasurementResponsibility;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\MeasurementReview;
use App\Models\User;
use App\Services\Security\ClamAvFileScanner;
use App\Support\Uploads\LocalUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MeasurementPaymentFinancialService
{
    /**
     * O caminho da medição paga cuja posição financeira não se determina: o
     * registro da Engenharia dela não volta à Engenharia
     * ({@see MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL}), e a
     * revisão só se abre depois da etapa Pagamento e dos comprovantes.
     */
    public const PAID_UNDETERMINED_POSITION_PATH = 'Esta medição tem pagamento registrado: o registro aprovado pela Engenharia é a base desse pagamento e não volta à Engenharia para ser reescrito. Aprove a etapa Pagamento com o que já foi pago, conclua a conferência dos comprovantes e corrija os dados por uma revisão formal (Criar revisão).';

    /**
     * O empreendimento sem Fundo de Obra na versão do plano congelada não tem
     * valor aprovado: nenhum pagamento é registrado nele -- nulo nunca é zero,
     * nem licença para pagar sem limite.
     */
    public const MISSING_FUND_PAYMENT_REFUSAL = 'O empreendimento %s não possui Fundo de Obra válido para esta medição: sem valor aprovado não há base financeira, e nenhum pagamento é registrado sem ela. O Fundo de Obra vem da versão do plano congelada pela Engenharia e não se corrige pelo fluxo da medição: peça ao administrador a correção do cadastro do plano.';

    /**
     * O que passa do saldo a pagar só é registrado com uma regra financeira
     * que o cubra; a justificativa sozinha não basta.
     */
    public const EXCESS_WITHOUT_RULE_REFUSAL = 'O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença.';

    public const RULE_UNAVAILABLE_REFUSAL = 'A regra escolhida não vale para este pagamento: ela é de outra emissão ou obra, foi encerrada ou não está vigente na data do pagamento.';

    /**
     * Seguida de quem registrou qual versão: "Você registrou a versão 2 desta
     * regra." ou "Você age por delegação de NOME, que registrou ...".
     */
    public const RULE_AUTHOR_SELF_USE_REFUSAL = 'Quem cria ou amplia uma regra financeira não a usa para autorizar a própria exceção: o pagamento enquadrado nela precisa ser registrado ou reavaliado por outro responsável.';

    public function __construct(
        private MeasurementFinancialReconciliationService $reconciliation,
        private MeasurementFinancialRuleService $rules,
        private DocumentStorageService $storage,
    ) {}

    /**
     * O enquadramento financeiro de um pagamento -- no registro e na
     * reavaliação --, a regra única da medição original e da revisão:
     *
     * - **pagamento comum**: positivo e até o saldo a pagar do empreendimento
     *   (valor aprovado menos o que a família já registrou). Pagar a menos é
     *   divergência justificada, aceita pelo Finalizador, com regra opcional;
     * - **pagamento excepcional**: o que passa do saldo a pagar -- seja ele
     *   zero, R$ 0,01 ou qualquer outro -- só é registrado com uma regra
     *   financeira vigente que cubra a divergência ACUMULADA do empreendimento
     *   na competência ({@see self::coverage()}); a justificativa sozinha não
     *   autoriza excedente algum;
     * - **sem Fundo de Obra**: nenhum pagamento -- sem valor aprovado não há
     *   base financeira.
     *
     * A exposição é a do momento do registro: os pagamentos desta medição
     * registrados ANTES deste (e o que a família pagou antes da revisão). Na
     * reavaliação, um pagamento registrado depois não desfaz a autorização
     * deste -- e a reavaliação não cria autorização: sem regra escolhida, a
     * atual continua enquanto cobrir; a regra escolhida precisa existir quando
     * o pagamento foi registrado, e quem é autor da versão da regra não a usa.
     *
     * Chamado dentro da transação do fluxo, sob o lock de quem chama: o
     * registro de pagamento já travou a Operation, a medição e os planos, nessa
     * ordem; a reavaliação travou a medição e o pagamento. Daqui em diante só
     * a regra financeira é travada -- depois deles, como manda a ordem
     * canônica de {@see MeasurementWorkflow}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function assess(Measurement $measurement, array $data, User $actor, ?MeasurementPayment $existing = null): array
    {
        Validator::make($data, [
            'financial_rule_id' => ['nullable', 'integer'],
            'financial_justification' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $planSetId = (int) $data['plan_set_id'];
        $line = $this->exposureLine($measurement, $planSetId, $data['amount'], $existing);
        if ($line === null) {
            throw ValidationException::withMessages(['financial_rule_id' => 'Empreendimento fora da aprovação da Engenharia.']);
        }
        if (! $line->hasFinancialReference()) {
            throw ValidationException::withMessages(['financial_rule_id' => sprintf(self::MISSING_FUND_PAYMENT_REFUSAL, $line->label)]);
        }
        $exception = in_array($line->status, [MeasurementReconciliationStatus::Under, MeasurementReconciliationStatus::Over], true);
        $excess = $line->status === MeasurementReconciliationStatus::Over;
        // Na reavaliação, a regra atual do pagamento -- a autorização com que
        // entrou --, enquanto ela cobrir a divergência daquele momento, mesmo
        // encerrada depois: sem regra escolhida, ela continua, e trocar a
        // justificativa ou o documento de suporte não a tira.
        $currentRule = $existing?->financial_assessment['rule'] ?? null;
        $currentRule = is_array($currentRule) && self::isReadableRuleSnapshot($currentRule)
            && $this->coverage($currentRule, $line)->covered
            ? $currentRule
            : null;
        $keptRule = blank($data['financial_rule_id'] ?? null) ? $currentRule : null;

        $justification = trim((string) ($data['financial_justification'] ?? ''));

        // Espaço invisível (sem quebra, de largura zero) não é justificativa.
        if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $justification) === '') {
            $justification = '';
        }
        Validator::make(['financial_justification' => $justification], [
            'financial_justification' => [$exception ? 'required' : 'nullable', 'string', 'max:5000'],
        ], ['financial_justification.required' => 'Explique a divergência financeira para a conferência do Finalizador.'])->validate();

        $rule = null;
        if (filled($data['financial_rule_id'] ?? null)) {
            $rule = $this->rules->availableFor($measurement, $planSetId, $data['pay_date'])
                ->whereKey($data['financial_rule_id'])->lockForUpdate()->first();
            if (! $rule instanceof MeasurementFinancialRule) {
                throw ValidationException::withMessages(['financial_rule_id' => self::RULE_UNAVAILABLE_REFUSAL]);
            }
            $coverage = $this->coverage($rule, $line);
            if (! $coverage->covered) {
                throw ValidationException::withMessages(['financial_rule_id' => 'A regra escolhida não cobre este pagamento. '.$coverage->refusal.($currentRule !== null
                    ? sprintf(' Escolha uma regra que cubra o pagamento, ou deixe a regra em branco para manter a atual (%s · versão %d).', $currentRule['name'] ?? 'regra', (int) ($currentRule['version'] ?? 1))
                    : '')]);
            }
            // A reavaliação corrige o enquadramento; não cria autorização para
            // um pagamento que já tinha sido feito.
            if ($existing instanceof MeasurementPayment && $rule->created_at !== null && $existing->created_at !== null
                && $rule->created_at->gt($existing->created_at)) {
                throw ValidationException::withMessages(['financial_rule_id' => sprintf(
                    'Esta versão da regra foi registrada depois do pagamento #%d: a reavaliação não cria autorização retroativa. Deixe a regra em branco para manter a atual, ou escolha uma regra que já existia quando o pagamento foi registrado.',
                    $existing->id,
                )]);
            }
        }

        $appliedRule = $rule?->snapshot() ?? $keptRule;

        if ($excess && $appliedRule === null) {
            throw ValidationException::withMessages(['financial_rule_id' => $this->excessWithoutRuleMessage($line)]);
        }

        // Quem registrou (ou ampliou) a regra -- esta versão ou as que ela
        // substitui -- não a usa na própria exceção, nem por meio de quem age
        // por delegação dele: a autoria vem das linhas da regra, imutáveis. A
        // regra mantida numa reavaliação de outra pessoa já foi escolhida por
        // quem registrou o pagamento, independente do autor, e quem reavalia
        // passa a ser conferido na Finalização: só quem escolhe a regra -- ou
        // reavalia o próprio pagamento -- responde por ela aqui.
        $choosesTheRule = $rule instanceof MeasurementFinancialRule
            || ! $existing instanceof MeasurementPayment
            || (int) $existing->created_by === (int) $actor->getKey();

        if ($appliedRule !== null && $choosesTheRule) {
            $authorization = app(MeasurementAuthorizationService::class)->resolveAuthorization($actor, $measurement, MeasurementResponsibility::PaymentManager);
            $delegatorId = $authorization->isDelegated() ? $authorization->delegation?->delegator_user_id : null;
            $conflict = $this->ruleAuthorConflict(
                $this->rules->chainOf((int) ($appliedRule['id'] ?? 0)),
                (int) $actor->getKey(),
                $delegatorId === null ? null : (int) $delegatorId,
            );

            if ($conflict !== null) {
                throw ValidationException::withMessages(['financial_rule_id' => $conflict]);
            }
        }

        $support = $existing?->financial_assessment['support'] ?? null;
        $file = $data['financial_support'] ?? null;
        if ($file !== null) {
            Validator::make(['financial_support' => $file], [
                'financial_support' => ['file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'],
            ])->validate();
            $support = $this->storeSupport($file);
        }
        if (($appliedRule['requires_document'] ?? false) === true && $support === null) {
            throw ValidationException::withMessages(['financial_support' => 'Esta regra exige documento de suporte (PDF, JPG ou PNG).']);
        }
        if ($support !== null) {
            $this->ensureSupportIntegrity($support);
        }

        return [
            'schema_version' => 2,
            'plan_set_id' => $planSetId,
            'pay_date' => $data['pay_date'],
            'amount' => $this->reconciliation->normalizeAmount($data['amount']),
            'engineering_hash' => $this->engineeringHash($measurement),
            'reference' => $line->toArray(),
            'requires_acceptance' => $exception,
            // O excedente que este pagamento acrescentou e o acumulado do
            // empreendimento com ele -- o que a regra precisou cobrir. Somar os
            // acumulados contaria duas vezes o que já tinha passado; somar os
            // acrescentados, não. Zero no pagamento comum.
            'excess_amount' => self::addedExcess($line),
            'cumulative_excess_amount' => $excess ? (string) $line->divergenceAmount : '0.00',
            'justification' => $justification ?: null,
            'rule' => $appliedRule,
            'coverage' => $appliedRule === null ? null : $this->coverage($appliedRule, $line)->toArray(),
            'support' => $support,
            'assessed_by_user_id' => (int) $actor->getKey(),
            'assessed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * O excedente que o pagamento acrescentou ao empreendimento: o acumulado
     * com ele menos o que já passava do aprovado antes dele -- nunca negativo.
     */
    private static function addedExcess(MeasurementFinancialReconciliationLine $line): string
    {
        if ($line->divergenceAmount === null || bccomp($line->divergenceAmount, '0', 2) <= 0) {
            return '0.00';
        }

        $before = bcsub($line->divergenceAmount, $line->enteredAmount, 2);

        return bcsub($line->divergenceAmount, self::nonNegative($before), 2);
    }

    /**
     * A conciliação do empreendimento com este valor, como no registro do
     * pagamento: os pagamentos desta medição registrados antes dele (todos,
     * no registro) mais o histórico da família.
     */
    private function exposureLine(Measurement $measurement, int $planSetId, mixed $amount, ?MeasurementPayment $existing = null): ?MeasurementFinancialReconciliationLine
    {
        // Numa cópia: quem chama continua com as relações que carregou.
        $subject = $measurement->withoutRelations();
        $subject->setRelation('payments', $measurement->payments()
            ->when($existing instanceof MeasurementPayment, fn ($query) => $query->where('id', '<', $existing->id))
            ->get());

        return $this->reconciliation->forMeasurement($subject, [$planSetId => $amount])->line($planSetId);
    }

    /**
     * As regras que o registro (ou a reavaliação) deste pagamento aceitaria
     * escolher: as da emissão e da obra vigentes na data do pagamento
     * ({@see MeasurementFinancialRuleService::availableFor()}), menos as que
     * esta pessoa registrou (ou cujo autor é quem lhe delegou o pagamento), as
     * registradas depois do pagamento reavaliado e as que não autorizam
     * divergência alguma (sem limite, ou com o percentual da base anterior).
     * O domínio confere tudo de novo.
     *
     * @return Collection<int, MeasurementFinancialRule>
     */
    public function selectableRules(Measurement $measurement, int $planSetId, string $paymentDate, User $actor, ?MeasurementPayment $existing = null): Collection
    {
        $authorization = app(MeasurementAuthorizationService::class)->resolveAuthorization($actor, $measurement, MeasurementResponsibility::PaymentManager);
        $excluded = array_values(array_filter([
            (int) $actor->getKey(),
            $authorization->isDelegated() && $authorization->delegation?->delegator_user_id !== null ? (int) $authorization->delegation->delegator_user_id : null,
        ]));

        $rules = $this->rules->availableFor($measurement->withoutRelations(), $planSetId, $paymentDate)
            ->orderBy('name')
            ->get();
        $chains = $this->rules->chainsOf(array_map('intval', $rules->modelKeys()));

        return $rules
            ->reject(fn (MeasurementFinancialRule $rule): bool => array_intersect(MeasurementFinancialRuleService::authorsIn($chains[(int) $rule->id] ?? []), $excluded) !== []
                || ($existing instanceof MeasurementPayment && $rule->created_at !== null && $existing->created_at !== null && $rule->created_at->gt($existing->created_at))
                // Sem limite nenhum, ou com o percentual da base anterior -- que
                // nunca autoriza e, com os dois limites valendo juntos, impede
                // a regra inteira --, ela não cobre nada.
                || ($rule->maximum_difference_amount === null && $rule->maximum_difference_percent === null)
                || ($rule->maximum_difference_percent !== null && $rule->percent_basis !== MeasurementFinancialRuleCoverage::PERCENT_BASIS_APPROVED_AMOUNT))
            ->values();
    }

    /**
     * Por que esta pessoa não usa a regra, ou `null`: ela -- ou quem lhe
     * delegou o pagamento -- registrou a versão aplicada ou uma das que ela
     * substitui.
     *
     * @param  list<array{id: int, version: int, created_by: int|null}>  $chain  {@see MeasurementFinancialRuleService::chainOf()}
     */
    private function ruleAuthorConflict(array $chain, int $actorId, ?int $delegatorId): ?string
    {
        $versionsBy = fn (int $userId): array => array_values(array_map(
            fn (array $version): int => $version['version'],
            array_filter($chain, fn (array $version): bool => $version['created_by'] === $userId),
        ));
        $applied = $chain[0]['version'] ?? null;
        $own = $versionsBy($actorId);

        if ($own !== []) {
            return sprintf('%s Você registrou %s.', self::RULE_AUTHOR_SELF_USE_REFUSAL, self::ruleVersionsPhrase($own, $applied));
        }

        $delegated = $delegatorId === null ? [] : $versionsBy($delegatorId);

        if ($delegated === []) {
            return null;
        }

        return sprintf(
            '%s Você age por delegação de %s, que registrou %s.',
            self::RULE_AUTHOR_SELF_USE_REFUSAL,
            User::query()->whereKey($delegatorId)->value('name') ?? 'o delegante',
            self::ruleVersionsPhrase($delegated, $applied),
        );
    }

    /**
     * "a versão 2 desta regra", ou "a versão 1 desta regra, que a versão 2
     * substitui" quando a aplicada é outra.
     *
     * @param  list<int>  $versions
     */
    private static function ruleVersionsPhrase(array $versions, ?int $applied): string
    {
        sort($versions);
        $last = array_pop($versions);
        $label = $versions === []
            ? sprintf('a versão %d desta regra', $last)
            : sprintf('as versões %s e %d desta regra', implode(', ', $versions), $last);

        return $applied !== null && $applied !== $last && ! in_array($applied, $versions, true)
            ? sprintf('%s, que a versão %d substitui', $label, $applied)
            : $label;
    }

    /**
     * A cobertura da regra para este valor, para o modal mostrar antes do
     * envio -- a mesma conta do registro, sem lock. `null` sem regra ou sem
     * empreendimento.
     */
    public function coverageFor(Measurement $measurement, int $planSetId, mixed $amount, mixed $ruleId, string $paymentDate, ?MeasurementPayment $existing = null): ?MeasurementFinancialRuleCoverage
    {
        if (blank($ruleId) || filter_var($ruleId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $rule = $this->rules->availableFor($measurement->withoutRelations(), $planSetId, $paymentDate)->whereKey((int) $ruleId)->first();
        $line = $this->exposureLine($measurement->withoutRelations(), $planSetId, $amount, $existing);

        return $rule instanceof MeasurementFinancialRule && $line !== null ? $this->coverage($rule, $line) : null;
    }

    /**
     * A recusa do excedente sem regra, com a conta do empreendimento.
     */
    private function excessWithoutRuleMessage(MeasurementFinancialReconciliationLine $line): string
    {
        return sprintf(
            '%s %s: valor aprovado %s, já registrado %s, saldo a pagar %s; com este pagamento, o excedente acumulado chega a %s. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.',
            self::EXCESS_WITHOUT_RULE_REFUSAL,
            $line->label,
            MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount),
            MeasurementFinancialReconciliationService::formatCurrency(bcadd($line->registeredAmount, $line->historicalPaidAmount, 2)),
            MeasurementFinancialReconciliationService::formatCurrency(self::nonNegative((string) $line->expectedBalance)),
            MeasurementFinancialReconciliationService::formatCurrency((string) $line->divergenceAmount),
        );
    }

    /**
     * A regra congelada num enquadramento pode ser relida como regra: direção
     * conhecida, ao menos um limite e limites escritos como a regra os grava.
     * Um enquadramento adulterado não vira regra sem limite nem quebra a conta.
     *
     * @param  array<mixed>  $snapshot
     */
    private static function isReadableRuleSnapshot(array $snapshot): bool
    {
        $amount = $snapshot['maximum_difference_amount'] ?? null;
        $percent = $snapshot['maximum_difference_percent'] ?? null;

        return is_string($snapshot['direction'] ?? null)
            && array_key_exists($snapshot['direction'], MeasurementFinancialRule::DIRECTIONS)
            && ($amount !== null || $percent !== null)
            && ($amount === null || (is_string($amount) && preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) === 1))
            && ($percent === null || (is_string($percent) && preg_match('/^\d{1,6}(\.\d{1,4})?$/', $percent) === 1));
    }

    /**
     * A regra cobre a divergência acumulada do empreendimento?
     *
     * - a divergência é a da linha: tudo o que a família registrou para o
     *   empreendimento até este pagamento, inclusive, menos o valor aprovado --
     *   o limite não recomeça a cada pagamento, nem conta duas vezes o que já
     *   foi pago;
     * - direção: "para mais" cobre excedente, "para menos" cobre pagamento
     *   abaixo do saldo, "ambas" cobre os dois;
     * - limite em reais: teto absoluto da divergência acumulada;
     * - limite percentual: percentual do VALOR APROVADO do empreendimento na
     *   competência -- nunca do saldo, que muda a cada pagamento parcial e é
     *   negativo depois de pago a maior. Só vale na regra cadastrada com essa
     *   base (`percent_basis`); a versão anterior a ela tinha o percentual
     *   sobre o saldo e não autoriza por percentual. Sem valor aprovado
     *   positivo, o limite percentual não autoriza nada;
     * - os dois limites informados valem juntos; regra sem limite nenhum não
     *   cobre nada.
     *
     * Conta em BCMath, sem float.
     *
     * @param  MeasurementFinancialRule|array<string, mixed>  $rule
     */
    public function coverage(MeasurementFinancialRule|array $rule, MeasurementFinancialReconciliationLine $line): MeasurementFinancialRuleCoverage
    {
        $values = $rule instanceof MeasurementFinancialRule
            ? ['direction' => $rule->direction, 'maximum_difference_amount' => $rule->maximum_difference_amount, 'maximum_difference_percent' => $rule->maximum_difference_percent, 'percent_basis' => $rule->percent_basis]
            : $rule;
        $ruleDirection = (string) ($values['direction'] ?? '');
        $amountLimit = self::decimalOrNull($values['maximum_difference_amount'] ?? null);
        $percentLimit = self::decimalOrNull($values['maximum_difference_percent'] ?? null);
        $percentBasis = is_string($values['percent_basis'] ?? null) ? $values['percent_basis'] : null;
        $difference = $line->divergenceAmount;
        $reference = $line->expectedAmount;
        $absolute = $difference === null ? null : ltrim($difference, '-');
        $direction = match (true) {
            $difference === null || bccomp($difference, '0', 2) === 0 => 'none',
            bccomp($difference, '0', 2) < 0 => 'under',
            default => 'over',
        };
        $percentApplies = $percentLimit !== null && $percentBasis === MeasurementFinancialRuleCoverage::PERCENT_BASIS_APPROVED_AMOUNT;
        $percentCeiling = $percentApplies && $reference !== null && bccomp($reference, '0', 2) > 0
            ? bcdiv(bcmul($reference, $percentLimit, 8), '100', 2)
            : null;

        $refusal = match (true) {
            $difference === null || $reference === null => 'Sem Fundo de Obra válido não há valor aprovado com que medir a divergência, e nenhuma regra a cobre.',
            $direction === 'none' => 'O pagamento não diverge do saldo a pagar: não há divergência a enquadrar numa regra.',
            $amountLimit === null && $percentLimit === null => 'A regra não tem limite em reais nem percentual e não autoriza divergência alguma.',
            ! in_array($ruleDirection, ['both', $direction], true) => sprintf(
                'A regra permite divergência %s, e este pagamento diverge %s do saldo a pagar.',
                mb_strtolower(MeasurementFinancialRule::DIRECTIONS[$ruleDirection] ?? 'em outra direção'),
                $direction === 'over' ? 'para mais' : 'para menos',
            ),
            $amountLimit !== null && bccomp((string) $absolute, $amountLimit, 2) > 0 => sprintf(
                'A divergência acumulada do empreendimento chega a %s, acima do limite em reais da regra (%s).',
                MeasurementFinancialReconciliationService::formatCurrency($absolute),
                MeasurementFinancialReconciliationService::formatCurrency($amountLimit),
            ),
            $percentLimit !== null && ! $percentApplies => 'O limite percentual desta versão da regra foi cadastrado sobre o saldo a pagar, base que deixou de valer: ele não autoriza divergência. Registre uma nova versão da regra (o percentual passa a ser do valor aprovado).',
            $percentLimit !== null && $percentCeiling === null => sprintf(
                'O valor aprovado do empreendimento é %s: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual%s.',
                MeasurementFinancialReconciliationService::formatCurrency($reference),
                $amountLimit === null ? '' : ' (os dois limites valem juntos: o percentual impede a regra mesmo com o limite em reais atendido)',
            ),
            $percentLimit !== null && bccomp(bcmul((string) $absolute, '100', 8), bcmul($reference, $percentLimit, 8), 8) > 0 => sprintf(
                'A divergência acumulada do empreendimento chega a %s, acima de %s do valor aprovado (%s), isto é, %s.',
                MeasurementFinancialReconciliationService::formatCurrency($absolute),
                MeasurementFinancialReconciliationService::formatPercent(rtrim(rtrim($percentLimit, '0'), '.')),
                MeasurementFinancialReconciliationService::formatCurrency($reference),
                MeasurementFinancialReconciliationService::formatCurrency((string) $percentCeiling),
            ),
            default => null,
        };

        return new MeasurementFinancialRuleCoverage(
            covered: $refusal === null,
            refusal: $refusal,
            direction: $direction,
            divergenceAmount: $absolute,
            referenceAmount: $reference,
            maximumDifferenceAmount: $amountLimit,
            maximumDifferencePercent: $percentLimit,
            percentBasis: $percentBasis,
            percentCeilingAmount: $percentCeiling,
        );
    }

    /**
     * Valor decimal da regra como string canônica, ou `null`.
     */
    private static function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? $value : (string) $value;

        // Só algarismos e ponto (o BCMath recusa notação científica). Um
        // limite ilegível não vira "sem limite": vale zero, e nada passa.
        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : '0';
    }

    /**
     * Se a Finalização exige o aceite expresso do Finalizador: há pagamento com
     * divergência justificada ou empreendimento exigido sem pagamento
     * ({@see self::unpaidRequiredPlanSets()}). É o que mostra e torna
     * obrigatória a confirmação no modal; a Finalização confere de novo.
     */
    public function requiresAcceptance(Measurement $measurement): bool
    {
        $payments = $measurement->payments()->get();

        return $payments->contains(
            fn (MeasurementPayment $payment): bool => (bool) ($payment->financial_assessment['requires_acceptance'] ?? false),
        ) || $this->unpaidRequiredPlanSetsAmong($measurement, $payments) !== []
            || ($measurement->isRevision() && app(MeasurementRevisionService::class)->unacknowledgedOverpayments($measurement) !== []);
    }

    /**
     * A posição financeira autoritativa da etapa Pagamento: quanto ainda é
     * devido em cada empreendimento e se a conta pode ser feita
     * ({@see MeasurementAmountDue}). É a regra única de "nada a pagar" -- da
     * medição original e da revisão -- que a aprovação da etapa Pagamento,
     * {@see MeasurementWorkflow::hasValidPayment()},
     * {@see MeasurementReceiptEvidenceService::allPaymentsApproved()} e a
     * Finalização consultam, e que as telas da medição original (pendências,
     * área de pagamentos, modais) usam para nenhuma delas discordar. As
     * etiquetas da revisão na área de pagamentos ainda olham só o "família já
     * pagou" (dívida registrada).
     *
     * O universo é o registro aprovado pela Engenharia, conferido entrada por
     * entrada antes de qualquer conta: registro verificável desta medição e
     * desta operação, empreendimento identificado uma vez só, versão do plano
     * congelada, percentual realizado legível e Fundo de Obra legível -- o
     * Fundo ausente deixa o empreendimento sem referência financeira, e o
     * valor devido indeterminado. A conciliação lê o percentual ausente como
     * 0%; aqui ele nunca vira "nada a pagar". Os empreendimentos aprovados
     * precisam continuar na operação, como o registro de pagamento exige.
     *
     * A conta é a de sempre, sem fórmula própria: a da conciliação na medição
     * original (Fundo × realizado do mês, menos o que esta medição registrou)
     * e a das posições da família na revisão (aprovado revisado, menos o que a
     * família e a revisão já pagaram, com o valor pago a maior a reconhecer).
     *
     * Só lê: quem decide trava antes. A aprovação da etapa Pagamento consulta
     * sob o lock da medição -- pagamento só nasce sob esse mesmo lock --, e a
     * Finalização sob o da Operation e o da medição.
     */
    public function amountDue(Measurement $measurement): MeasurementAmountDue
    {
        // Leituras que escolhem as colunas (as listas, as pendências) não trazem
        // o registro da Engenharia: a conta relê a medição inteira, em vez de
        // tomar a coluna não lida por registro indisponível.
        if ($measurement->exists && ! array_key_exists('engineering_snapshot', $measurement->getAttributes())) {
            $measurement = Measurement::query()->find($measurement->getKey()) ?? $measurement;
        }

        $snapshot = $measurement->engineering_snapshot;
        $undetermined = $this->undeterminedAmountDue($measurement, $snapshot);
        $lines = [];

        if ($undetermined === []) {
            $lines = $measurement->isRevision()
                ? $this->revisionAmountDueLines($measurement)
                : $this->originalAmountDueLines($measurement);

            if ($lines === []) {
                $undetermined[] = $measurement->isRevision()
                    ? 'A posição financeira da revisão não pode ser calculada: o registro da Engenharia da revisão anterior não está disponível.'
                    : 'O registro da Engenharia não tem empreendimento algum: o valor devido não pode ser determinado.';
            }

            foreach ($lines as $line) {
                if (! $line->hasFinancialReference()) {
                    $undetermined[] = sprintf(
                        '%s: sem Fundo de Obra na versão do plano congelada pela Engenharia — o valor devido não pode ser determinado.',
                        $line->label,
                    );
                }
            }
        }

        return new MeasurementAmountDue(
            measurementId: (int) $measurement->getKey(),
            isRevision: $measurement->isRevision(),
            lines: $lines,
            undetermined: $undetermined,
            engineeringSnapshotSha256: is_array($snapshot) ? MeasurementRevisionService::canonicalSnapshotHash($snapshot) : null,
        );
    }

    /**
     * Na medição original, o pagamento precisa de um valor devido a que
     * corresponder -- e o que passar dele, de uma regra financeira que o
     * autorize.
     *
     * Chamado pelo registro de pagamentos ({@see MeasurementWorkflow::registerPayments()})
     * linha a linha, sob o lock da Operation e da medição e antes do
     * enquadramento. A posição é a autoritativa ({@see self::amountDue()}),
     * relida a cada linha: o que as linhas anteriores do mesmo registro pagaram
     * já conta, e um empreendimento não compensa outro.
     *
     * - Sem posição financeira -- o registro da Engenharia não sustenta a
     *   conta --, nenhum pagamento é registrado: valor devido indeterminado
     *   nunca é zero, nem licença para pagar.
     * - Sem Fundo de Obra no empreendimento, nenhum pagamento: sem valor
     *   aprovado não há base financeira (o Fundo de Obra zero é Fundo válido).
     * - Até o saldo a pagar, o pagamento é comum; pagar a menos segue como
     *   divergência justificada, com o aceite do Finalizador.
     * - Acima do saldo a pagar -- zero, R$ 0,01 ou qualquer outro --, o
     *   pagamento só passa com uma regra financeira, que o enquadramento
     *   confere contra o excedente acumulado (emissão, obra, vigência,
     *   direção, limites, documento e autoria). A justificativa sozinha não
     *   basta.
     *
     * A revisão tem a regra própria ({@see MeasurementRevisionService::assertRevisionPaymentFits()}).
     *
     * @param  array<string, mixed>  $row
     *
     * @throws ValidationException
     */
    public function assertOriginalPaymentFits(Measurement $measurement, array $row, int $index): void
    {
        if ($measurement->isRevision()) {
            return;
        }

        $field = "payments.{$index}.amount";
        $planSetId = (int) ($row['plan_set_id'] ?? 0);
        $amountDue = $this->amountDue($measurement);
        $line = collect($amountDue->lines)->first(fn (MeasurementAmountDueLine $line): bool => $line->planSetId === $planSetId);

        if (! $line instanceof MeasurementAmountDueLine) {
            throw ValidationException::withMessages([$field => sprintf(
                'Nenhum pagamento é registrado sem a posição financeira desta medição. %s %s',
                implode(' ', $amountDue->undetermined ?: ['O empreendimento não está no registro aprovado pela Engenharia: o valor devido não pode ser determinado.']),
                // A medição paga não volta à Engenharia: o caminho é a revisão.
                $measurement->payments()->exists()
                    ? self::PAID_UNDETERMINED_POSITION_PATH
                    : 'Corrija o registro da Engenharia, devolvendo a medição àquela etapa.',
            )]);
        }

        if (! $line->hasFinancialReference()) {
            throw ValidationException::withMessages([$field => sprintf(self::MISSING_FUND_PAYMENT_REFUSAL, $line->label)]);
        }

        $amount = $this->reconciliation->normalizeAmount($row['amount'] ?? null);

        if (bccomp($amount, (string) $line->remainingAmount, 2) <= 0 || filled($row['financial_rule_id'] ?? null)) {
            return;
        }

        $excess = bcsub(bcadd($line->registeredAmount, $amount, 2), (string) $line->approvedAmount, 2);

        throw ValidationException::withMessages([$field => sprintf(
            '%s %s: valor aprovado %s, já registrado %s, saldo a pagar %s; com este pagamento, o excedente acumulado chega a %s. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.%s',
            self::EXCESS_WITHOUT_RULE_REFUSAL,
            $line->label,
            MeasurementFinancialReconciliationService::formatCurrency($line->approvedAmount),
            MeasurementFinancialReconciliationService::formatCurrency($line->registeredAmount),
            MeasurementFinancialReconciliationService::formatCurrency($line->remainingAmount),
            MeasurementFinancialReconciliationService::formatCurrency($excess),
            $amountDue->isNothingDue() && $measurement->payments()->doesntExist()
                ? ' Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.'
                : '',
        )]);
    }

    /**
     * A etapa Pagamento pode terminar sem pagamento desta medição? Quando nada
     * é devido, demonstrado ({@see self::amountDue()}) -- e, na revisão, também
     * quando a família já pagou antes dela: o que falta ou sobra é a posição
     * financeira da família, conferida à parte (saldo justificado, valor pago a
     * maior decidido).
     *
     * Na medição original isso só diz que o pagamento não é exigido: a etapa
     * termina pela decisão expressa e justificada de concluí-la sem desembolso
     * ({@see MeasurementWorkflow::approve()}), e a Finalização exige a ciência
     * dessa decisão.
     */
    public function allowsCompletionWithoutOwnPayment(Measurement $measurement): bool
    {
        if ($measurement->isRevision() && app(MeasurementRevisionService::class)->familyHasHistoricalPayments($measurement)) {
            return true;
        }

        return $this->amountDue($measurement)->isNothingDue();
    }

    /**
     * Por que o registro da Engenharia não sustenta a conta do valor devido --
     * vazio quando sustenta.
     *
     * Percentual e Fundo de Obra só valem escritos como o registro os grava
     * (decimal com ponto, sem separador de milhar): a conta lê o valor de um
     * jeito só, e um texto que outro leitor entenderia de outro modo ("1.000",
     * "R$ 1.000,00") não é conta determinada.
     *
     * O registro aprovado antes do versionamento dos planos (Fase 2) não tem a
     * versão do plano dentro dele: como na Finalização, o Fundo de Obra dele é
     * conferido contra a versão gravada no arquivo da medição para aquele
     * empreendimento. A versão presente e ilegível nunca passa.
     *
     * @return list<string>
     */
    private function undeterminedAmountDue(Measurement $measurement, mixed $snapshot): array
    {
        if (! Measurement::isVerifiableEngineeringSnapshot($snapshot, (int) $measurement->getKey())
            || (int) ($snapshot['operation_id'] ?? 0) !== (int) $measurement->operation_id) {
            return ['O registro aprovado pela Engenharia desta medição não está disponível ou não é verificável: o valor devido não pode ser determinado.'];
        }

        $problems = [];
        $planSetIds = [];
        $legacyEntries = [];

        foreach ($snapshot['plan_sets'] as $entry) {
            $planSetId = is_array($entry) ? filter_var($entry['plan_set_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            if ($planSetId === false) {
                $problems[] = 'O registro da Engenharia tem empreendimento sem identificação: o valor devido não pode ser determinado.';

                continue;
            }

            $label = (string) (($entry['construction_name'] ?? null) ?: ($entry['plan_set_name'] ?? null) ?: "Empreendimento #{$planSetId}");

            if (in_array($planSetId, $planSetIds, true)) {
                $problems[] = sprintf('%s aparece mais de uma vez no registro da Engenharia: o valor devido não pode ser determinado.', $label);

                continue;
            }

            $planSetIds[] = $planSetId;

            if (! array_key_exists('plan_version_id', $entry)) {
                $legacyEntries[$planSetId] = ['label' => $label, 'fund' => $entry['construction_fund_amount'] ?? null];
            } elseif (filter_var($entry['plan_version_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $problems[] = sprintf('%s: sem versão do plano congelada no registro da Engenharia — o valor devido não pode ser determinado.', $label);
            }

            $percent = $entry['realized_monthly_percent'] ?? null;

            if (! self::isCanonicalDecimal($percent)
                || ($basisPoints = MeasurementPhysicalProgress::basisPoints((string) $percent)) === null
                || $basisPoints > MeasurementPhysicalProgress::LIMIT_BASIS_POINTS) {
                $problems[] = sprintf('%s: percentual realizado do mês ausente ou ilegível no registro da Engenharia — o valor devido não pode ser determinado.', $label);
            }

            // Ausente ou nulo é "sem referência financeira", tratado na linha;
            // presente, o Fundo precisa ser um valor em reais, zero ou positivo.
            $fund = $entry['construction_fund_amount'] ?? null;

            if ($fund !== null && ! self::isCanonicalDecimal($fund)) {
                $problems[] = sprintf('%s: Fundo de Obra ilegível no registro da Engenharia — o valor devido não pode ser determinado.', $label);
            }
        }

        if ($legacyEntries !== []) {
            $problems = [...$problems, ...$this->legacyFundProblems($measurement, $legacyEntries)];
        }

        if ($planSetIds !== []
            && MeasurementPlanSet::query()->where('operation_id', $measurement->operation_id)->whereKey($planSetIds)->count() !== count($planSetIds)) {
            $problems[] = 'O contexto aprovado pela Engenharia não está mais disponível: há empreendimento fora da operação. O valor devido não pode ser determinado.';
        }

        return $problems;
    }

    /**
     * O Fundo de Obra do registro anterior ao versionamento confere com a
     * versão do plano gravada no arquivo da medição? A mesma conferência da
     * Finalização ({@see MeasurementWorkflow::finalize()}), feita antes, para a
     * decisão sem desembolso não se apoiar num Fundo que a Finalização recusaria.
     *
     * @param  array<int, array{label: string, fund: mixed}>  $legacyEntries
     * @return list<string>
     */
    private function legacyFundProblems(Measurement $measurement, array $legacyEntries): array
    {
        $versionIds = MeasurementAsset::query()
            ->where('measurement_id', $measurement->getKey())
            ->whereIn('plan_set_id', array_keys($legacyEntries))
            ->pluck('plan_version_id', 'plan_set_id');
        $funds = MeasurementPlanVersion::query()
            ->whereKey($versionIds->filter()->values()->all())
            ->pluck('construction_fund_amount', 'id');
        $problems = [];

        foreach ($legacyEntries as $planSetId => $entry) {
            $versionId = $versionIds->get($planSetId);
            $versionFund = $versionId === null ? null : $funds->get($versionId);

            if ($versionId === null || ! $funds->has($versionId) || ! self::sameMoney($versionFund, $entry['fund'])) {
                $problems[] = sprintf('%s: o registro da Engenharia, anterior ao versionamento do plano, não confere com a versão do plano do arquivo da medição — o valor devido não pode ser determinado.', $entry['label']);
            }
        }

        return $problems;
    }

    /**
     * Decimal como o registro da Engenharia o grava: algarismos, ponto e até
     * duas casas -- ou inteiro.
     */
    private static function isCanonicalDecimal(mixed $value): bool
    {
        return is_int($value) ? $value >= 0 : is_string($value) && preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value) === 1;
    }

    /**
     * Mesmo valor em reais: nulo só confere com nulo.
     */
    private static function sameMoney(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return self::isCanonicalDecimal($left = is_string($left) ? $left : (string) $left)
            && self::isCanonicalDecimal($right = is_string($right) ? $right : (string) $right)
            && bccomp($left, $right, 2) === 0;
    }

    /**
     * A conta da conciliação, com os pagamentos relidos agora -- a página passa
     * o próprio registro, com relações carregadas que podem ser anteriores ao
     * último pagamento.
     *
     * @return list<MeasurementAmountDueLine>
     */
    private function originalAmountDueLines(Measurement $measurement): array
    {
        $current = $measurement->withoutRelations()->setRelation('payments', $measurement->payments()->get());
        $entries = $this->snapshotEntriesByPlanSet($measurement);

        return array_map(fn (MeasurementFinancialReconciliationLine $line): MeasurementAmountDueLine => new MeasurementAmountDueLine(
            planSetId: $line->planSetId,
            label: $line->label,
            constructionId: $line->constructionId,
            planVersionId: isset($entries[$line->planSetId]['plan_version_id']) ? (int) $entries[$line->planSetId]['plan_version_id'] : null,
            planVersionNumber: isset($entries[$line->planSetId]['plan_version_number']) ? (int) $entries[$line->planSetId]['plan_version_number'] : null,
            fundAmount: $line->fundAmount,
            realizedMonthlyPercent: $line->realizedMonthlyPercent,
            approvedAmount: $line->expectedAmount,
            paidBeforeAmount: $line->historicalPaidAmount,
            registeredAmount: $line->registeredAmount,
            remainingAmount: $line->expectedBalance === null ? null : self::nonNegative($line->expectedBalance),
        ), array_values($this->reconciliation->forMeasurement($current)->lines));
    }

    /**
     * As posições da família, sem conta nova.
     *
     * @return list<MeasurementAmountDueLine>
     */
    private function revisionAmountDueLines(Measurement $revision): array
    {
        $entries = $this->snapshotEntriesByPlanSet($revision);

        return array_map(fn (MeasurementRevisionPlanSetPosition $position): MeasurementAmountDueLine => new MeasurementAmountDueLine(
            planSetId: $position->planSetId,
            label: $position->label,
            constructionId: isset($entries[$position->planSetId]['construction_id']) ? (int) $entries[$position->planSetId]['construction_id'] : null,
            planVersionId: $position->planVersionId,
            planVersionNumber: $position->planVersionNumber,
            fundAmount: $position->revisedFundAmount,
            realizedMonthlyPercent: $position->revisedRealizedMonthlyPercent,
            approvedAmount: $position->revisedApprovedAmount,
            paidBeforeAmount: $position->historicalPaidAmount,
            registeredAmount: $position->ownPaidAmount,
            remainingAmount: $position->remainingPayableAmount,
            unacknowledgedOverpaymentAmount: $position->unacknowledgedOverpaymentAmount,
        ), app(MeasurementRevisionService::class)->positions($revision));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotEntriesByPlanSet(Measurement $measurement): array
    {
        return collect($measurement->engineering_snapshot['plan_sets'] ?? [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->keyBy(fn (array $entry): int => (int) ($entry['plan_set_id'] ?? 0))
            ->all();
    }

    private static function nonNegative(string $amount): string
    {
        return bccomp($amount, '0', 2) < 0 ? '0.00' : $amount;
    }

    /**
     * Empreendimentos que a Engenharia aprovou com valor esperado a pagar e que
     * não têm nenhum pagamento DESTA medição.
     *
     * A justificativa e o aceite de divergência ficam em cada pagamento, então
     * quem não recebeu nada não tinha onde guardá-los e passava pela etapa
     * Pagamento e pela Finalização sem justificativa nem aceite. O universo é
     * o `engineering_snapshot` -- conferido contra os arquivos e os planos na
     * Finalização --, e a conta é a da conciliação, sem fórmula própria.
     *
     * Não é exigido o de valor esperado zero (0% realizado, fundo zero ou
     * valor arredondado a zero): nada ali pede aceite. O empreendimento sem
     * Fundo de Obra (registro anterior à exigência na ativação do plano) não
     * recebe pagamento ({@see self::assess()}), e nulo nunca é zero: com avanço
     * no mês, a ausência de pagamento dele também precisa da justificativa da
     * etapa Pagamento e do aceite do Finalizador -- não passa em silêncio.
     * Quem recebeu parte do valor também não entra: a diferença é divergência
     * do próprio pagamento, já justificada e aceita por ele.
     *
     * Relê os pagamentos sem alterar a medição recebida -- a página passa o
     * próprio registro, com relações carregadas que ela continua usando.
     *
     * @return list<MeasurementFinancialReconciliationLine>
     */
    public function unpaidRequiredPlanSets(Measurement $measurement): array
    {
        return $this->unpaidRequiredPlanSetsAmong($measurement, $measurement->payments()->get());
    }

    /**
     * O que fazer na etapa Pagamento com o empreendimento deixado sem
     * pagamento: o sem Fundo de Obra não recebe pagamento, só a justificativa.
     *
     * @param  list<MeasurementFinancialReconciliationLine>  $lines
     */
    public static function unpaidRemedy(array $lines): string
    {
        $undetermined = array_filter($lines, fn (MeasurementFinancialReconciliationLine $line): bool => ! $line->hasFinancialReference());

        return match (true) {
            $undetermined === [] => 'Devolva à etapa Pagamento para registrar o pagamento ou justificar a ausência.',
            count($undetermined) === count($lines) => 'Devolva à etapa Pagamento para justificar a ausência: sem Fundo de Obra, o empreendimento não recebe pagamento.',
            default => 'Devolva à etapa Pagamento para registrar o pagamento ou justificar a ausência; o empreendimento sem Fundo de Obra não recebe pagamento, só a justificativa.',
        };
    }

    /**
     * Os empreendimentos omitidos como a pessoa lê: nome e valor em aberto --
     * o esperado, ou, numa revisão, o esperado menos o que a família já pagou
     * antes dela (sem pagamento próprio, as duas contas coincidem na medição
     * sem revisão).
     *
     * @param  list<MeasurementFinancialReconciliationLine>  $lines
     */
    public function describeUnpaidPlanSets(array $lines): string
    {
        return collect($lines)
            ->map(fn (MeasurementFinancialReconciliationLine $line): string => sprintf(
                '%s (%s)',
                $line->label,
                $line->hasFinancialReference()
                    ? MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance)
                    : 'valor devido indeterminado: sem Fundo de Obra',
            ))
            ->implode(', ');
    }

    /**
     * Confere, na Finalização, a ausência de pagamento justificada na etapa
     * Pagamento e devolve o que foi aceito, para a auditoria congelar.
     *
     * Roda depois de {@see self::acceptForFinalization()} e das conferências de
     * integridade. A justificativa é a nota da aprovação da etapa Pagamento;
     * em branco -- aprovação anterior a esta regra -- a medição precisa voltar
     * àquela etapa, porque o Finalizador não aceita o que ninguém justificou.
     *
     * @return list<array{plan_set_id: int, reference: array<string, mixed>, justification: string, justified_by: int|null, justified_at: string|null}>
     */
    public function acceptUnpaidPlanSetsForFinalization(Measurement $measurement, bool $accepted): array
    {
        $unpaid = $this->unpaidRequiredPlanSets($measurement);

        if ($unpaid === []) {
            return [];
        }

        $paymentReview = $measurement->reviews()
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $justification = trim((string) $paymentReview?->notes);

        if (! $paymentReview instanceof MeasurementReview || $justification === '') {
            throw new MeasurementWorkflowException(
                'Há empreendimento sem pagamento nesta competência e sem justificativa da etapa Pagamento: '.$this->describeUnpaidPlanSets($unpaid).'. '.self::unpaidRemedy($unpaid),
                [
                    'measurement_id' => $measurement->getKey(),
                    'unpaid_plan_set_ids' => array_map(fn (MeasurementFinancialReconciliationLine $line): int => $line->planSetId, $unpaid),
                ],
            );
        }

        if (! $accepted) {
            throw ValidationException::withMessages([
                'accept_financial_exceptions' => 'Confirme expressamente o aceite da ausência de pagamento justificada na etapa Pagamento: '.$this->describeUnpaidPlanSets($unpaid).'.',
            ]);
        }

        return array_map(fn (MeasurementFinancialReconciliationLine $line): array => [
            'plan_set_id' => $line->planSetId,
            'reference' => $line->toArray(),
            'justification' => $justification,
            'justified_by' => $paymentReview->reviewer_user_id === null ? null : (int) $paymentReview->reviewer_user_id,
            'justified_at' => $paymentReview->reviewed_at?->toIso8601String(),
        ], $unpaid);
    }

    /**
     * Confere, na Finalização de uma revisão, o valor pago a maior que ela
     * identificou e que nenhuma finalização anterior reconheceu -- a família
     * pagou, antes dela, mais do que ela aprova -- e devolve o que foi aceito,
     * para a auditoria congelar. Nada é estornado nem compensado: os pagamentos
     * registrados ficam como estão, e o aceite é o reconhecimento expresso de
     * que o valor continua em aberto, sem devolução registrada no sistema.
     *
     * A decisão financeira é a nota da aprovação da etapa Pagamento da própria
     * revisão; em branco, a revisão volta àquela etapa.
     *
     * @return list<array<string, mixed>>
     */
    public function acceptRevisionOverpaymentsForFinalization(Measurement $measurement, bool $accepted): array
    {
        if (! $measurement->isRevision()) {
            return [];
        }

        $revisions = app(MeasurementRevisionService::class);
        $overpayments = $revisions->unacknowledgedOverpayments($measurement);

        if ($overpayments === []) {
            return [];
        }

        $paymentReview = $measurement->reviews()
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $decision = trim((string) $paymentReview?->notes);

        if (! $paymentReview instanceof MeasurementReview || $decision === '') {
            throw new MeasurementWorkflowException(
                'Esta revisão deixa valor pago a maior sem a decisão financeira da etapa Pagamento: '.$revisions->describeOverpayments($overpayments).'. Devolva à etapa Pagamento para registrar a decisão.',
                ['measurement_id' => $measurement->getKey()],
            );
        }

        if (! $accepted) {
            throw ValidationException::withMessages([
                'accept_financial_exceptions' => 'Confirme expressamente o aceite do valor pago a maior desta revisão ainda não reconhecido, que continua em aberto, sem devolução registrada no sistema: '.$revisions->describeOverpayments($overpayments).'.',
            ]);
        }

        return array_map(fn ($position): array => [
            'plan_set_id' => $position->planSetId,
            'position' => $position->toArray(),
            'decision' => $decision,
            'decided_by' => $paymentReview->reviewer_user_id === null ? null : (int) $paymentReview->reviewer_user_id,
            'decided_at' => $paymentReview->reviewed_at?->toIso8601String(),
        ], $overpayments);
    }

    /**
     * @param  Collection<int, MeasurementPayment>  $payments
     * @return list<MeasurementFinancialReconciliationLine>
     */
    private function unpaidRequiredPlanSetsAmong(Measurement $measurement, Collection $payments): array
    {
        $paidPlanSetIds = $payments
            ->pluck('plan_set_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();
        $current = $measurement->withoutRelations()->setRelation('payments', $payments);

        return array_values(array_filter(
            $this->reconciliation->forMeasurement($current)->lines,
            fn (MeasurementFinancialReconciliationLine $line): bool => ! in_array($line->planSetId, $paidPlanSetIds, true)
                && ($line->hasFinancialReference()
                    ? bccomp((string) $line->expectedBalance, '0', MeasurementFinancialReconciliationService::MONEY_SCALE) > 0
                    : bccomp($line->realizedMonthlyPercent, '0', MeasurementFinancialReconciliationService::MONEY_SCALE) > 0),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function acceptForFinalization(Measurement $measurement, bool $accepted): array
    {
        $assessments = [];
        foreach ($measurement->payments()->lockForUpdate()->get() as $payment) {
            $assessment = $payment->financial_assessment;
            if ($assessment === null) {
                continue;
            }
            if (! $this->assessmentMatchesSnapshot($measurement, $payment)) {
                throw new MeasurementWorkflowException('O contexto financeiro mudou. Devolva à etapa Pagamento para reavaliar o enquadramento.');
            }
            if ($assessment['support'] ?? null) {
                try {
                    $this->ensureSupportIntegrity($assessment['support']);
                } catch (ValidationException) {
                    throw new MeasurementWorkflowException('O documento de suporte do pagamento #'.$payment->id.' está ausente ou inválido. Devolva à etapa Pagamento para substituir o documento.');
                }
            }
            // O excedente registrado antes da Fase 4B-1 só com a justificativa
            // -- o saldo de R$ 0,01 o deixava passar -- não tem autorização a
            // aceitar: a Finalização não o aceita.
            if (($assessment['reference']['status'] ?? null) === MeasurementReconciliationStatus::Over->value
                && ! is_array($assessment['rule'] ?? null)) {
                throw new MeasurementWorkflowException(sprintf(
                    'O pagamento #%d excede o saldo autorizado sem regra financeira que cubra a diferença (registro anterior à exigência da regra): a justificativa sozinha não autoriza o excedente, e ele não é aceito na Finalização. Devolva à etapa Pagamento e reavalie o enquadramento com uma regra financeira que já existia quando o pagamento foi registrado; sem ela, a medição precisa ser corrigida pelo procedimento de dados.',
                    $payment->id,
                ), ['measurement_id' => $measurement->getKey(), 'payment_id' => $payment->id]);
            }
            if ($assessment['requires_acceptance'] ?? false) {
                if (! $accepted) {
                    throw ValidationException::withMessages(['accept_financial_exceptions' => 'Confirme expressamente o aceite das divergências e justificativas financeiras.']);
                }
                $assessments[] = ['payment_id' => $payment->id, 'assessment' => $assessment];
            }
        }

        return $assessments;
    }

    /**
     * O enquadramento congelado no pagamento ainda corresponde ao registro da
     * Engenharia e ao próprio pagamento? A mesma conferência da Finalização,
     * sem lock: a elegibilidade da revisão da medição paga a lê sob o lock da
     * linha da medição, que é o que protege os pagamentos. Pagamento sem
     * enquadramento (anterior às regras financeiras) não tem o que conferir.
     */
    public function assessmentMatchesSnapshot(Measurement $measurement, MeasurementPayment $payment): bool
    {
        $assessment = $payment->financial_assessment;

        if ($assessment === null) {
            return true;
        }

        return ($assessment['engineering_hash'] ?? null) === $this->engineeringHash($measurement)
            && (int) ($assessment['plan_set_id'] ?? 0) === (int) $payment->plan_set_id
            && ($assessment['pay_date'] ?? null) === $payment->pay_date->toDateString()
            && ($assessment['amount'] ?? null) === $payment->amount;
    }

    private function engineeringHash(Measurement $measurement): string
    {
        return hash('sha256', json_encode($measurement->engineering_snapshot, JSON_THROW_ON_ERROR));
    }

    /**
     * Toda recusa do antivírus vai para o log como crítica, como em
     * {@see ScansUploadedFile::rejectUploadedFile()}: a falha da
     * varredura é engolida sem relatório, e o antivírus fora do ar bloqueia
     * todo pagamento com documento de suporte sem deixar rastro.
     *
     * @return array<string, mixed>
     */
    private function storeSupport(UploadedFile $file): array
    {
        $scanner = app(ClamAvFileScanner::class);
        /**
         * A varredura lê o arquivo por um caminho local -- uma cópia, quando o
         * envio temporário está num disco remoto. Arquivo ilegível nunca passa.
         */
        $scanResult = $scanner->isEnabled()
            ? rescue(
                fn (): string => LocalUploadedFile::using($file, fn (string $path): string => $scanner->scan($path)),
                ClamAvFileScanner::RESULT_UNAVAILABLE,
                report: false,
            )
            : ClamAvFileScanner::RESULT_CLEAN;
        if ($scanResult !== ClamAvFileScanner::RESULT_CLEAN) {
            Log::critical('Upload bloqueado pela varredura antivírus.', [
                'reason' => $scanResult === ClamAvFileScanner::RESULT_INFECTED ? 'malware_detectado' : 'antivirus_indisponivel',
                'field' => 'financial_support',
                'original_filename' => $file->getClientOriginalName(),
            ]);

            throw ValidationException::withMessages(['financial_support' => 'O documento não passou na verificação de segurança ou o antivírus está indisponível.']);
        }
        $stored = $this->storage->storePrivateFile($file, 'measurements/financial-support');
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => Storage::disk($stored['disk'])->delete($stored['path']));
            }
        }
        try {
            app(MeasurementFileValidationService::class)->validateReceipt($stored['path'], $stored['disk']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'financial_support' => collect($exception->errors())->flatten()->all(),
            ]);
        }
        $metadata = $this->storage->metadata($stored['path'], $stored['disk']);

        return [
            'disk' => $stored['disk'], 'path' => $stored['path'], 'sha256' => $this->storage->checksum($stored['path'], $stored['disk']),
            'name' => $file->getClientOriginalName(), 'mime_type' => $metadata['mime_type'], 'size' => $metadata['size_bytes'],
        ];
    }

    /** @param array<string, mixed> $support */
    public function ensureSupportIntegrity(array $support): void
    {
        $path = $support['path'] ?? '';
        $disk = $support['disk'] ?? '';
        if (! $this->storage->isAllowedMeasurementWriteDisk($disk) || ! $this->storage->isSafeStoredPath($path)) {
            throw ValidationException::withMessages(['financial_support' => 'Documento de suporte indisponível.']);
        }
        $hash = $this->storage->checksum($path, $disk);
        if (! is_string($hash) || ! is_string($support['sha256'] ?? null) || ! hash_equals($support['sha256'], $hash)) {
            throw ValidationException::withMessages(['financial_support' => 'Documento de suporte ausente ou com integridade inválida.']);
        }
    }
}
