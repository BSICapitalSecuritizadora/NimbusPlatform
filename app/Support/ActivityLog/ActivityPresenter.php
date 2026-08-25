<?php

namespace App\Support\ActivityLog;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Enums\ProposalStatus;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\Reconciliation\FieldChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Camada de apresentação do audit log (spatie/laravel-activitylog).
 *
 * Converte eventos técnicos (event, properties.attributes, properties.old) em
 * uma representação operacional humanizada para a linha do tempo, sem alterar
 * os dados registrados. O payload bruto permanece integralmente disponível na
 * área de detalhes técnicos de cada item.
 */
final class ActivityPresenter
{
    /**
     * Campos puramente técnicos: nunca aparecem na visão operacional, apenas
     * na área expansível de auditoria.
     *
     * @var array<int, string>
     */
    private const TECHNICAL_ONLY_FIELDS = [
        'id',
        'submission_token',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /** @var array<string, string> */
    private const FIELD_LABELS = [
        // Proposta / Operação
        'status' => 'Situação',
        'company_id' => 'Empresa',
        'contact_id' => 'Contato',
        'assigned_representative_id' => 'Responsável',
        'distribution_sequence' => 'Fila',
        'distributed_at' => 'Distribuída em',
        'completed_at' => 'Concluída em',
        'observations' => 'Observação',
        'internal_notes' => 'Nota interna',

        // Emissão
        'name' => 'Nome',
        'code' => 'Código',
        'bsi_code' => 'Código BSI',
        'phase' => 'Fase',
        'operation_type' => 'Tipo de operação',
        'benchmark' => 'Indexador',
        'nominal_rate' => 'Taxa nominal',
        'spread_rate' => 'Spread',
        'issue_amount' => 'Volume da emissão',
        'series_count' => 'Número de séries',
        'trustee_id' => 'Agente fiduciário',
        'lead_underwriter_id' => 'Coordenador líder',
        'issuer_name' => 'Emissora',
        'issue_date' => 'Data de emissão',
        'maturity_date' => 'Data de vencimento',
        'tax_type' => 'Tributação',
        'amortization_type' => 'Amortização',

        // Obrigação
        'title' => 'Título',
        'obligation_category' => 'Categoria',
        'priority' => 'Prioridade',
        'responsible_user_id' => 'Responsável',
        'responsible_area' => 'Área responsável',
        'due_date' => 'Vencimento',
        'competence_date' => 'Competência',
        'recurrence' => 'Recorrência',
        'description' => 'Descrição',
        'document_status' => 'Situação documental',

        // Garantia
        'type' => 'Tipo de garantia',
        'current_value' => 'Valor atual',
        'contract_amount' => 'Valor em contrato',
        'grace_period_months' => 'Carência (meses)',

        // Contrato / Unidade
        'contract_number' => 'Número do contrato',
        'total_amount' => 'Valor total',
        'buyer_name' => 'Comprador',
        'signed_at' => 'Assinado em',

        /**
         * Conciliação de contratos e parcelas. Os rótulos são os mesmos que
         * {@see FieldChange} usa na conferência da
         * planilha, para que o operador leia a mesma palavra antes e depois de
         * confirmar a importação.
         */
        'sale_date' => 'Data da venda',
        'sale_value' => 'Valor de venda',
        'cancellation_date' => 'Data de cancelamento',
        'number' => 'Número',
        'expected_value' => 'Valor previsto',
        'payment_date' => 'Data do pagamento',
        'paid_value' => 'Valor pago',

        // Cliente
        'document' => 'Documento (CPF/CNPJ)',
        'person_type' => 'Tipo de pessoa',
        'email' => 'E-mail',
        'phone' => 'Telefone',

        // Usuário / Recrutamento
        'is_active' => 'Ativo',
        'approved_at' => 'Aprovado em',
        'role' => 'Função',
        'stage' => 'Etapa',
        'score' => 'Pontuação',
        'feedback' => 'Parecer',
        'notes' => 'Notas',
        'justification' => 'Justificativa',
    ];

    /**
     * Rótulos que dependem do tipo do registro: a mesma coluna é lida com
     * palavras diferentes em cada domínio. `cancellation_date` é distrato num
     * contrato e cancelamento numa parcela -- exatamente como a conferência da
     * planilha os chama.
     *
     * Consultado antes de {@see FIELD_LABELS}, que continua valendo para o resto.
     *
     * @var array<string, array<string, string>>
     */
    private const SUBJECT_FIELD_LABELS = [
        'Contract' => [
            'cancellation_date' => 'Data do distrato',
            'code' => 'Contrato',
        ],
        'ContractInstallment' => [
            'cancellation_date' => 'Data de cancelamento',
            'number' => 'Parcela',
        ],
    ];

    /**
     * Campos gravados como decimal e apresentados em reais. O valor da activity
     * permanece intocado -- `"2500.00"` continua sendo `"2500.00"`; só a leitura
     * muda.
     *
     * @var array<int, string>
     */
    private const MONEY_FIELDS = [
        'sale_value',
        'expected_value',
        'paid_value',
    ];

    /** @var array<int, string> */
    private const LONG_TEXT_FIELDS = [
        'observations',
        'internal_notes',
        'description',
        'feedback',
        'notes',
        'justification',
    ];

    /** @var array<string, array{model: class-string, attribute: string}> */
    private const REFERENCE_RESOLVERS = [
        'company_id' => ['model' => ProposalCompany::class, 'attribute' => 'name'],
        'contact_id' => ['model' => ProposalContact::class, 'attribute' => 'name'],
        'assigned_representative_id' => ['model' => ProposalRepresentative::class, 'attribute' => 'name'],
        'responsible_user_id' => ['model' => User::class, 'attribute' => 'name'],
        'user_id' => ['model' => User::class, 'attribute' => 'name'],
        'created_by' => ['model' => User::class, 'attribute' => 'name'],
        'completed_by' => ['model' => User::class, 'attribute' => 'name'],
        'lead_underwriter_id' => ['model' => User::class, 'attribute' => 'name'],
        'emission_id' => ['model' => Emission::class, 'attribute' => 'name'],
        'vacancy_id' => ['model' => Vacancy::class, 'attribute' => 'title'],
    ];

    /** @var array<string, array{created: string, updated: string, deleted: string, restored?: string}> */
    private const SUBJECT_TITLES = [
        'Proposal' => [
            'created' => 'Proposta criada',
            'updated' => 'Proposta atualizada',
            'deleted' => 'Proposta removida',
            'restored' => 'Proposta restaurada',
        ],
        'Emission' => [
            'created' => 'Emissão criada',
            'updated' => 'Emissão atualizada',
            'deleted' => 'Emissão removida',
            'restored' => 'Emissão restaurada',
        ],
        'Obligation' => [
            'created' => 'Obrigação cadastrada',
            'updated' => 'Obrigação atualizada',
            'deleted' => 'Obrigação removida',
            'restored' => 'Obrigação restaurada',
        ],
        'Guarantee' => [
            'created' => 'Garantia registrada',
            'updated' => 'Garantia atualizada',
            'deleted' => 'Garantia removida',
        ],
        'LegalInstrument' => [
            'created' => 'Instrumento cadastrado',
            'updated' => 'Instrumento atualizado',
            'deleted' => 'Instrumento removido',
        ],
        'Contract' => [
            'created' => 'Contrato registrado',
            'updated' => 'Contrato atualizado',
            'deleted' => 'Contrato removido',
        ],
        'Client' => [
            'created' => 'Cliente cadastrado',
            'updated' => 'Cliente atualizado',
            'deleted' => 'Cliente removido',
        ],
        'JobApplication' => [
            'created' => 'Candidatura recebida',
            'updated' => 'Candidatura atualizada',
            'deleted' => 'Candidatura removida',
        ],
        'Document' => [
            'created' => 'Documento anexado',
            'updated' => 'Documento atualizado',
            'deleted' => 'Documento removido',
        ],
        'User' => [
            'created' => 'Usuário cadastrado',
            'updated' => 'Usuário atualizado',
            'deleted' => 'Usuário removido',
        ],
    ];

    /**
     * Cache de resolução de referências (company_id → nome, etc.) válido
     * apenas para o request atual, evitando uma query por evento repetido.
     *
     * @var array<string, string|null>
     */
    private static array $referenceCache = [];

    /**
     * As alterações de campo de uma activity, já rotuladas e humanizadas.
     *
     * Exposto para quem precisa só do diff -- a listagem de alterações de uma
     * importação, por exemplo -- sem montar a linha do tempo inteira.
     *
     * @return array<int, ActivityChange>
     */
    public static function changesFor(Activity $activity): array
    {
        return self::extractChanges($activity);
    }

    /**
     * Como o registro afetado deve ser chamado na tela.
     *
     * Usa os relacionamentos já carregados; nada aqui dispara query. Um subject
     * que não existe mais -- ou que o eager loading não trouxe -- cai no
     * identificador técnico, que continua sendo informação de auditoria válida.
     */
    public static function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if ($subject instanceof ContractInstallment) {
            $code = $subject->contract?->code;

            return $code === null
                ? sprintf('Parcela %s', $subject->number)
                : sprintf('Contrato %s · Parcela %s', $code, $subject->number);
        }

        if ($subject instanceof Contract) {
            return sprintf('Contrato %s', $subject->code);
        }

        if ($activity->subject_type === null) {
            return 'Execução da importação';
        }

        return sprintf('%s #%s', class_basename((string) $activity->subject_type), $activity->subject_id);
    }

    public static function present(Activity $activity): ActivityTimelineItem
    {
        $changes = self::extractChanges($activity);
        $classification = self::classify($activity, $changes);
        $technicalDetails = self::technicalDetailsFor($activity);
        $rawJson = self::rawPayloadJson($activity);

        return new ActivityTimelineItem(
            title: $classification['title'],
            icon: $classification['icon'],
            color: $classification['color'],
            author: $activity->causer?->name ?? 'Sistema',
            authorAvatarUrl: $activity->causer instanceof User ? $activity->causer->avatarUrl() : null,
            occurredAt: $activity->created_at ?? now(),
            changes: $changes,
            technicalDetails: $technicalDetails,
            technicalDetailsAsText: self::technicalDetailsAsText($activity, $technicalDetails),
            eventType: $activity->event ?? 'system',
            eventLabel: self::eventLabel($activity->event),
            rawJson: $rawJson,
            metadata: [
                'batch_uuid' => $activity->batch_uuid,
                'log_name' => $activity->log_name,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
            ],
        );
    }

    /**
     * Label amigável para um valor de `event`, usada também nos filtros.
     */
    public static function eventLabel(?string $event): string
    {
        return match ($event) {
            'created' => 'Criação',
            'updated' => 'Atualização',
            'deleted' => 'Remoção',
            'restored' => 'Restauração',
            'distributed' => 'Distribuição',
            'status_changed' => 'Mudança de status',
            'completed' => 'Conclusão',
            'submitted_for_review' => 'Envio para análise',
            'reopened' => 'Reabertura',
            'comment_added' => 'Comentário',
            null, '' => 'Sistema',
            default => Str::headline(str_replace('_', ' ', $event)),
        };
    }

    /**
     * @return array<int, ActivityChange>
     */
    private static function extractChanges(Activity $activity): array
    {
        $subject = class_basename((string) $activity->subject_type);
        $properties = $activity->properties ?? collect();
        $attributes = $properties->get('attributes', []);
        $old = $properties->get('old', []);

        if (! is_array($attributes)) {
            return [];
        }

        $changes = [];

        foreach ($attributes as $key => $newValue) {
            if (in_array($key, self::TECHNICAL_ONLY_FIELDS, true)) {
                continue;
            }

            if (Str::endsWith($key, '_id') && ! isset(self::REFERENCE_RESOLVERS[$key])) {
                continue;
            }

            $oldValue = is_array($old) && array_key_exists($key, $old) ? $old[$key] : null;

            // Nunca apresenta como alteração um valor que permaneceu igual.
            if (array_key_exists($key, is_array($old) ? $old : []) && self::sameValue($oldValue, $newValue)) {
                continue;
            }

            $changes[] = new ActivityChange(
                key: $key,
                label: self::labelFor($key, $subject),
                old: $activity->event === 'updated' ? self::humanizeValue($key, $oldValue, $subject) : null,
                new: self::humanizeValue($key, $newValue, $subject),
                oldColor: self::colorForValue($key, $oldValue, $subject),
                newColor: self::colorForValue($key, $newValue, $subject),
                isLongText: in_array($key, self::LONG_TEXT_FIELDS, true),
                isInternal: $key === 'internal_notes',
            );
        }

        return $changes;
    }

    /**
     * @param  array<int, ActivityChange>  $changes
     * @return array{title: string, icon: string, color: string}
     */
    private static function classify(Activity $activity, array $changes): array
    {
        $subject = class_basename((string) $activity->subject_type);
        $event = (string) ($activity->event ?? '');

        if ($event === 'updated' && $changes !== []) {
            $keys = array_map(fn (ActivityChange $change): string => $change->key, $changes);

            if ($keys === ['status']) {
                return ['title' => 'Status alterado', 'icon' => 'heroicon-m-arrows-right-left', 'color' => 'info'];
            }

            if (in_array('assigned_representative_id', $keys, true) || in_array('responsible_user_id', $keys, true)) {
                if (in_array('distributed_at', $keys, true) || in_array('distribution_sequence', $keys, true)) {
                    return ['title' => 'Proposta distribuída', 'icon' => 'heroicon-m-paper-airplane', 'color' => 'success'];
                }

                return ['title' => 'Responsável atualizado', 'icon' => 'heroicon-m-user-circle', 'color' => 'info'];
            }

            if ($keys === ['observations']) {
                return ['title' => 'Observação atualizada', 'icon' => 'heroicon-m-chat-bubble-bottom-center-text', 'color' => 'gray'];
            }

            if ($keys === ['internal_notes']) {
                return ['title' => 'Nota interna atualizada', 'icon' => 'heroicon-m-lock-closed', 'color' => 'gray'];
            }

            if (in_array('status', $keys, true)) {
                return ['title' => 'Status alterado', 'icon' => 'heroicon-m-arrows-right-left', 'color' => 'info'];
            }

            if (in_array('completed_at', $keys, true)) {
                return ['title' => 'Operação concluída', 'icon' => 'heroicon-m-check-circle', 'color' => 'success'];
            }
        }

        // Evento de atualização sem diferenças reais não afirma alteração de campo.
        if ($event === 'updated' && $changes === []) {
            return ['title' => 'Registro atualizado', 'icon' => 'heroicon-m-arrow-path', 'color' => 'gray'];
        }

        if (isset(self::SUBJECT_TITLES[$subject][$event])) {
            return match ($event) {
                'created' => ['title' => self::SUBJECT_TITLES[$subject]['created'], 'icon' => 'heroicon-m-plus-circle', 'color' => 'success'],
                'deleted' => ['title' => self::SUBJECT_TITLES[$subject]['deleted'], 'icon' => 'heroicon-m-trash', 'color' => 'danger'],
                'restored' => ['title' => self::SUBJECT_TITLES[$subject]['restored'] ?? 'Registro restaurado', 'icon' => 'heroicon-m-arrow-path', 'color' => 'info'],
                default => ['title' => self::SUBJECT_TITLES[$subject]['updated'], 'icon' => 'heroicon-m-arrow-path', 'color' => 'gray'],
            };
        }

        // Logs manuais já registram a descrição em linguagem humana.
        $description = (string) $activity->description;

        if (filled($description) && ! in_array($description, ['created', 'updated', 'deleted', 'restored'], true) && ! Str::contains($description, '_')) {
            return ['title' => $description, 'icon' => 'heroicon-m-information-circle', 'color' => 'gray'];
        }

        return ['title' => 'Registro atualizado', 'icon' => 'heroicon-m-arrow-path', 'color' => 'gray'];
    }

    private static function labelFor(string $key, string $subject): string
    {
        return self::SUBJECT_FIELD_LABELS[$subject][$key]
            ?? self::FIELD_LABELS[$key]
            ?? Str::headline(str_replace('_', ' ', $key));
    }

    private static function humanizeValue(string $key, mixed $value, string $subject): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($resolved = self::resolveReference($key, $value)) {
            return $resolved;
        }

        if (in_array($key, self::MONEY_FIELDS, true) && is_scalar($value)) {
            return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
        }

        if ($key === 'status') {
            if ($subject === 'Proposal') {
                return ProposalStatus::labelFor(is_scalar($value) ? (string) $value : null);
            }

            if ($subject === 'Obligation' && is_string($value)) {
                return Obligation::STATUS_OPTIONS[$value] ?? Str::headline($value);
            }

            if ($subject === 'Contract' && is_string($value)) {
                return ContractStatus::tryFrom($value)?->label() ?? Str::headline($value);
            }
        }

        if ($key === 'priority' && is_string($value)) {
            return Obligation::PRIORITY_OPTIONS[$value] ?? Str::headline($value);
        }

        if ($key === 'distribution_sequence') {
            return '#'.$value;
        }

        if (Str::endsWith($key, '_at') && is_scalar($value)) {
            return Carbon::parse((string) $value)->format('d/m/Y \à\s H:i');
        }

        if (Str::endsWith($key, '_date') && is_scalar($value)) {
            return Carbon::parse((string) $value)->format('d/m/Y');
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private static function colorForValue(string $key, mixed $value, string $subject): ?string
    {
        if ($key === 'status') {
            if ($subject === 'Proposal' && filled($value) && is_scalar($value)) {
                return ProposalStatus::colorFor((string) $value);
            }

            if ($subject === 'Obligation' && is_string($value)) {
                return match ($value) {
                    'concluida' => 'success',
                    'em_analise' => 'warning',
                    'a_vencer' => 'info',
                    'vencida' => 'danger',
                    default => 'gray',
                };
            }

            if ($subject === 'Contract' && is_string($value)) {
                return ContractStatus::tryFrom($value)?->color() ?? 'gray';
            }
        }

        if ($key === 'priority' && is_string($value)) {
            return match ($value) {
                'critical' => 'danger',
                'high' => 'warning',
                'medium' => 'info',
                default => 'gray',
            };
        }

        return null;
    }

    private static function resolveReference(string $key, mixed $value): ?string
    {
        if (! isset(self::REFERENCE_RESOLVERS[$key]) || blank($value) || ! is_scalar($value)) {
            return null;
        }

        $cacheKey = $key.':'.$value;

        if (! array_key_exists($cacheKey, self::$referenceCache)) {
            $resolver = self::REFERENCE_RESOLVERS[$key];

            self::$referenceCache[$cacheKey] = $resolver['model']::query()->find($value)?->{$resolver['attribute']};
        }

        return self::$referenceCache[$cacheKey];
    }

    /**
     * Payload técnico completo do evento, preservado para auditoria.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function technicalDetailsFor(Activity $activity): array
    {
        $details = [
            ['label' => 'ID do evento', 'value' => (string) $activity->id],
            ['label' => 'Evento', 'value' => $activity->event ?? '—'],
            ['label' => 'Descrição registrada', 'value' => $activity->description ?? '—'],
            ['label' => 'Data/Hora completa', 'value' => $activity->created_at?->format('d/m/Y H:i:s') ?? '—'],
            ['label' => 'Autor', 'value' => $activity->causer
                ? "{$activity->causer->name} (#{$activity->causer->getKey()})"
                : 'Sistema'],
        ];

        if ($activity->subject_type) {
            $details[] = [
                'label' => 'Objeto afetado',
                'value' => class_basename((string) $activity->subject_type)." #{$activity->subject_id}",
            ];
        }

        if ($activity->batch_uuid) {
            $details[] = ['label' => 'Batch UUID', 'value' => (string) $activity->batch_uuid];
        }

        $properties = $activity->properties ?? collect();

        foreach (['attributes' => 'Novo valor', 'old' => 'Valor anterior'] as $group => $groupLabel) {
            $values = $properties->get($group, []);

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                $details[] = [
                    'label' => "{$groupLabel} · {$key}",
                    'value' => self::rawValue($value),
                ];
            }
        }

        return $details;
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $details
     */
    private static function technicalDetailsAsText(Activity $activity, array $details): string
    {
        return implode("\n", array_map(
            fn (array $detail): string => "{$detail['label']}: {$detail['value']}",
            $details,
        ));
    }

    private static function rawPayloadJson(Activity $activity): string
    {
        $payload = [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'causer_type' => $activity->causer_type,
            'causer_id' => $activity->causer_id,
            'event' => $activity->event,
            'batch_uuid' => $activity->batch_uuid,
            'properties' => $activity->properties?->toArray() ?? [],
            'created_at' => $activity->created_at?->toIso8601String(),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function rawValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private static function sameValue(mixed $old, mixed $new): bool
    {
        return json_encode($old) === json_encode($new);
    }
}
