<?php

namespace App\Services;

use App\Concerns\ScansUploadedFile;
use App\DTOs\Measurements\MeasurementAmountDue;
use App\DTOs\Measurements\MeasurementAmountDueLine;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\MeasurementReview;
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
    public function __construct(
        private MeasurementFinancialReconciliationService $reconciliation,
        private MeasurementFinancialRuleService $rules,
        private DocumentStorageService $storage,
    ) {}

    /**
     * Chamado dentro da transação do fluxo, sob o lock de quem chama: o
     * registro de pagamento já travou a Operation, a medição e os planos, nessa
     * ordem; a reavaliação travou a medição e o pagamento. Daqui em diante só
     * a regra financeira é travada -- depois deles, como manda a ordem
     * canônica de {@see MeasurementWorkflow}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function assess(Measurement $measurement, array $data, ?MeasurementPayment $existing = null): array
    {
        Validator::make($data, [
            'financial_rule_id' => ['nullable', 'integer'],
            'financial_justification' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $measurement->setRelation('payments', $measurement->payments()->get()->reject(
            fn (MeasurementPayment $payment): bool => $payment->id === $existing?->id,
        ));
        $planSetId = (int) $data['plan_set_id'];
        $line = $this->reconciliation->forMeasurement($measurement, [$planSetId => $data['amount']])->line($planSetId);
        if ($line === null) {
            throw ValidationException::withMessages(['financial_rule_id' => 'Empreendimento fora da aprovação da Engenharia.']);
        }
        $exception = in_array($line->status, [MeasurementReconciliationStatus::Under, MeasurementReconciliationStatus::Over], true);
        $justification = trim((string) ($data['financial_justification'] ?? ''));
        Validator::make(['financial_justification' => $justification], [
            'financial_justification' => [$exception ? 'required' : 'nullable', 'string', 'max:5000'],
        ], ['financial_justification.required' => 'Explique a divergência financeira para a conferência do Finalizador.'])->validate();

        $rule = null;
        if (filled($data['financial_rule_id'] ?? null)) {
            $rule = $this->rules->availableFor($measurement, $planSetId, $data['pay_date'])
                ->whereKey($data['financial_rule_id'])->lockForUpdate()->first();
            if (! $rule || ! $this->covers($rule, $line->divergenceAmount, $line->expectedBalance)) {
                throw ValidationException::withMessages(['financial_rule_id' => 'A regra não cobre esta emissão, obra, data, direção ou valor. Escolha uma regra válida ou justifique como exceção sem regra.']);
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
        if ($rule?->requires_document && $support === null) {
            throw ValidationException::withMessages(['financial_support' => 'Esta regra exige documento de suporte (PDF, JPG ou PNG).']);
        }
        if ($support !== null) {
            $this->ensureSupportIntegrity($support);
        }

        return [
            'schema_version' => 1,
            'plan_set_id' => $planSetId,
            'pay_date' => $data['pay_date'],
            'amount' => $this->reconciliation->normalizeAmount($data['amount']),
            'engineering_hash' => $this->engineeringHash($measurement),
            'reference' => $line->toArray(),
            'requires_acceptance' => $exception,
            'justification' => $justification ?: null,
            'rule' => $rule?->snapshot(),
            'support' => $support,
        ];
    }

    private function covers(MeasurementFinancialRule $rule, ?string $difference, ?string $balance): bool
    {
        if ($difference === null || $balance === null || bccomp($difference, '0', 2) === 0) {
            return false;
        }
        $direction = bccomp($difference, '0', 2) < 0 ? 'under' : 'over';
        $absolute = ltrim($difference, '-');
        if (! in_array($rule->direction, ['both', $direction], true)
            || ($rule->maximum_difference_amount !== null && bccomp($absolute, $rule->maximum_difference_amount, 2) > 0)) {
            return false;
        }
        if ($rule->maximum_difference_percent !== null) {
            if (bccomp($balance, '0', 2) === 0) {
                return false;
            }

            return bccomp(bcmul($absolute, '100', 8), bcmul(ltrim($balance, '-'), $rule->maximum_difference_percent, 8), 8) <= 0;
        }

        return true;
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
     * Não é exigido o empreendimento sem referência financeira (sem fundo de
     * obra no snapshot) nem o de valor esperado zero (0% realizado, fundo zero
     * ou valor arredondado a zero): como em {@see self::assess()}, nada ali
     * pede aceite. Quem recebeu parte do valor também não entra: a diferença é
     * divergência do próprio pagamento, já justificada e aceita por ele.
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
                MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance),
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
                'Há empreendimento sem pagamento nesta competência e sem justificativa da etapa Pagamento: '.$this->describeUnpaidPlanSets($unpaid).'. Devolva à etapa Pagamento para registrar o pagamento ou justificar a ausência.',
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
            fn (MeasurementFinancialReconciliationLine $line): bool => $line->hasFinancialReference()
                && bccomp((string) $line->expectedBalance, '0', MeasurementFinancialReconciliationService::MONEY_SCALE) > 0
                && ! in_array($line->planSetId, $paidPlanSetIds, true),
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
