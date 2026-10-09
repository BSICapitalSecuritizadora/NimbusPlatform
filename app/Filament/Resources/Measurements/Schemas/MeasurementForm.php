<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Enums\OperationStatus;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementPlanVersionResolver;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MeasurementForm
{
    /**
     * Marcador do select pesquisável "Operação" de "Dados da Medição". O tema
     * libera o popup dos wrappers recortados da página, junto com o bloco dos
     * Responsáveis pelo Fluxo da operação; nenhum outro campo o recebe.
     */
    public const OPERATION_SELECT_CLASS = 'bsi-measurement-operation-select';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados da Medição')
                    ->description('Identifique a operação e confirme a competência do envio.')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('operation_id')
                            ->label('Operação')
                            ->extraAttributes(['class' => static::OPERATION_SELECT_CLASS])
                            ->placeholder('Selecione a operação...')
                            // Só a operação que aceitaria a medição nova desta
                            // pessoa entra (scopeOpenToNewMeasurementBy()), mas
                            // a que já está gravada continua listada: sem
                            // isso, abrir uma medição antiga de operação
                            // encerrada mostraria o campo vazio, como se ela
                            // tivesse perdido a operação. O backend recusa o
                            // payload manipulado de qualquer forma.
                            ->relationship(
                                'operation',
                                'title',
                                modifyQueryUsing: function (Builder $query, ?Measurement $record): Builder {
                                    $user = auth()->user();

                                    if (! $user instanceof User) {
                                        return $query->whereRaw('1 = 0');
                                    }

                                    return $query->visibleTo($user)->where(
                                        fn (Builder $eligible): Builder => $eligible
                                            ->where(fn (Builder $open): Builder => static::scopeOpenToNewMeasurementBy($open, $user))
                                            ->when(
                                                filled($record?->operation_id),
                                                fn (Builder $existing): Builder => $existing
                                                    ->orWhere('operations.id', $record->operation_id),
                                            ),
                                    );
                                },
                            )
                            ->getOptionLabelFromRecordUsing(fn (Operation $record): string => trim(($record->code ? $record->code.' — ' : '').$record->title))
                            ->searchable(['title', 'code'])
                            ->preload()
                            ->required()
                            ->disabled(fn (?Measurement $record): bool => $record instanceof Measurement)
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                                // Os arquivos são os dos planos que preveem medição na
                                // competência; o envio confere sob o lock que continuam
                                // os mesmos (CreateMeasurement::assertTheFilesCoverTheCompetence()).
                                if ($state !== $old) {
                                    $set('assets', static::assetsForOperation($state, $get('reference_month')));
                                }
                            })
                            ->validationMessages([
                                'required' => 'Selecione uma operação.',
                            ]),

                        // Com pagamento registrado, a competência fica presa ao
                        // que foi pago (Measurement::PAID_COMPETENCE_CHANGE_REFUSAL):
                        // o campo travado diz isso antes de a gravação recusar.
                        // No envio, a competência decide os empreendimentos e a
                        // versão do plano de cada um: mudá-la refaz a lista de
                        // arquivos (assetsForCompetence()). Depois do envio, a
                        // competência só muda dentro da vigência da versão
                        // congelada (Measurement::FROZEN_VERSION_COMPETENCE_CHANGE_REFUSAL).
                        DatePicker::make('reference_month')
                            ->label('Competência')
                            ->placeholder('mm/aaaa')
                            ->displayFormat('m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->live(condition: fn (string $operation): bool => $operation === 'create')
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, string $operation): void {
                                if ($operation === 'create' && filled($get('operation_id'))) {
                                    $set('assets', static::assetsForCompetence($get('operation_id'), $state, (array) ($get('assets') ?? [])));
                                }
                            })
                            // A competência decide a versão do plano de cada arquivo:
                            // a medição não nasce, nem fica, sem ela.
                            ->required()
                            ->disabled(fn (?Model $record): bool => static::hasRegisteredPayment($record))
                            ->helperText(fn (?Model $record, string $operation): string => match (true) {
                                static::hasRegisteredPayment($record) => 'Travada: esta medição tem pagamento registrado, e o pagamento continua vinculado a esta competência.',
                                $operation === 'create' => 'Preenchida automaticamente pela medição selecionada no cronograma. É a competência que decide a versão do plano de cada empreendimento.',
                                default => 'A versão do plano de cada arquivo ficou congelada no envio: a competência só muda junto com a medição prevista de cada arquivo, para outra que essa versão rege.',
                            })
                            ->validationMessages([
                                'required' => 'Informe a competência de referência.',
                            ]),

                        Textarea::make('notes')
                            ->label('Observações (Opcional)')
                            ->placeholder('Registre observações contextuais ou detalhes relevantes sobre este envio de medição...')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Arquivo por Empreendimento')
                    ->description('Associe a medição prevista e envie o arquivo correspondente para cada empreendimento.')
                    ->columnSpanFull()
                    ->schema([
                        // Plano ainda em rascunho não recebe medição: sem o aviso, a
                        // operação escolhida mostrava a seção vazia e o envio só
                        // respondia com o erro genérico do repeater.
                        Placeholder::make('plans_not_in_force_notice')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => static::plansNotInForceNotice($get('operation_id')) ?? '')
                            ->visible(fn (Get $get, ?Model $record): bool => ! $record instanceof Measurement
                                && filled($get('operation_id'))
                                && static::plansNotInForceNotice($get('operation_id')) !== null),

                        // A competência escolhida não tem medição prevista em nenhum
                        // empreendimento: sem o aviso, a lista de arquivos só
                        // esvaziava, e o motivo aparecia depois do envio.
                        Placeholder::make('competence_without_development_notice')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => static::noDevelopmentMessage($get('operation_id'), $get('reference_month')))
                            ->visible(fn (Get $get, ?Model $record): bool => ! $record instanceof Measurement
                                && filled($get('operation_id'))
                                && filled($get('reference_month'))
                                && blank($get('assets'))
                                && static::planSetIdsInForce($get('operation_id')) !== []),

                        Placeholder::make('empty_operation_notice')
                            ->hiddenLabel()
                            ->content(new HtmlString('<div class="flex flex-col items-center gap-1.5 px-6 py-10 text-center"><svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg><p class="text-sm font-semibold text-slate-600 dark:text-slate-300">Nenhuma operação selecionada</p><p class="max-w-md text-xs leading-relaxed text-slate-400 dark:text-slate-500">Selecione uma operação acima para carregar os empreendimentos vinculados e enviar os respectivos arquivos de medição.</p></div>'))
                            ->visible(fn (Get $get): bool => blank($get('operation_id'))),

                        Repeater::make('assets')
                            ->relationship()
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => array_merge($data, [
                                'storage_disk' => DocumentStorageService::privateDisk(),
                            ]))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, MeasurementAsset $record): array => array_merge($data, [
                                'storage_disk' => ($data['storage_path'] ?? null) !== $record->storage_path
                                    ? DocumentStorageService::privateDisk()
                                    : $record->storage_disk,
                            ]))
                            ->hiddenLabel()
                            ->addable(false)
                            // No envio, os arquivos são os empreendimentos que a
                            // competência envolve -- todos exigidos pela Engenharia:
                            // não se remove nenhum. O arquivo de medição paga não sai
                            // mais (MeasurementAsset::PAID_FILE_REMOVAL_REFUSAL): a
                            // Engenharia dela confere justamente os
                            // empreendimentos destes arquivos.
                            ->deletable(fn (?Model $record, string $operation): bool => $operation !== 'create' && ! static::hasRegisteredPayment($record))
                            ->deleteAction(fn (Action $action) => $action->tooltip('Remover empreendimento deste envio'))
                            ->reorderable(false)
                            ->minItems(1)
                            ->validationMessages([
                                'min' => fn (Get $get): string => static::noDevelopmentMessage($get('operation_id'), $get('reference_month')),
                            ])
                            ->columns(['default' => 1, 'md' => 2])
                            ->itemLabel(fn (array $state): ?string => filled($state['plan_set_id'] ?? null)
                                ? static::planSetLabel($state['plan_set_id'])
                                : 'Empreendimento')
                            ->visible(fn (Get $get): bool => filled($get('operation_id')))
                            ->schema([
                                Select::make('plan_set_id')
                                    ->label('Empreendimento')
                                    ->options(fn (Get $get): array => static::planSetOptions($get('../../operation_id')))
                                    ->required()
                                    ->disabled()
                                    ->dehydrated()
                                    ->validationMessages([
                                        'required' => 'Empreendimento inválido.',
                                    ]),

                                Select::make('plan_line_id')
                                    ->label('Medição do cronograma')
                                    ->placeholder('Selecione a medição prevista...')
                                    ->options(fn (Get $get, ?Model $record): array => static::scheduleOptionsForPlanSet(
                                        $get('plan_set_id'),
                                        $record instanceof MeasurementAsset ? $record : null,
                                    ))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required()
                                    // A linha do arquivo pago também fica presa
                                    // (MeasurementAsset::PAID_CONTEXT_CHANGE_REFUSAL). A do
                                    // arquivo já enviado só troca por outra competência da
                                    // versão congelada (FROZEN_VERSION_COMPETENCE_REFUSAL):
                                    // as opções são só essas, e a gravação confere de novo.
                                    ->disabled(fn (?Model $record): bool => static::hasRegisteredPayment($record))
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                        $date = filled($state)
                                            ? static::visiblePlanLinesQuery()->whereKey($state)->value('measurement_date')
                                            : null;

                                        if (blank($date)) {
                                            return;
                                        }

                                        $competence = Carbon::parse($date)->toDateString();
                                        $set('../../reference_month', $competence);

                                        // No envio, a medição prevista escolhida define a
                                        // competência da medição inteira: os outros
                                        // empreendimentos passam a ser os que preveem medição
                                        // nela, cada um pela versão que a rege.
                                        if ($operation === 'create') {
                                            $set('../../assets', static::assetsForCompetence($get('../../operation_id'), $competence, (array) ($get('../../assets') ?? [])));
                                        }
                                    })
                                    ->helperText(fn (?Model $record): string => match (true) {
                                        static::hasRegisteredPayment($record) => 'Travada: esta medição tem pagamento registrado, e o pagamento continua vinculado a esta linha.',
                                        $record instanceof MeasurementAsset && filled($record->plan_line_id) && $record->planVersion instanceof MeasurementPlanVersion => sprintf(
                                            'A medição foi enviada sob a %s do plano: só as competências regidas por ela aparecem aqui.',
                                            $record->planVersion->label(),
                                        ),
                                        default => 'Selecione a medição prevista à qual este arquivo corresponde. Cada competência aparece pela versão do plano que vale para ela.',
                                    })
                                    ->validationMessages([
                                        'required' => 'Selecione a medição do cronograma correspondente.',
                                        // A opção saiu da lista desde que a página abriu:
                                        // uma revisão passou a reger a competência, ou
                                        // outra medição ocupou a medição prevista.
                                        'in' => 'Esta medição prevista não está mais disponível: o plano foi revisado ou outra medição a ocupou. Recarregue a página e escolha de novo.',
                                    ]),

                                FileUpload::make('storage_path')
                                    ->label('Arquivo da Medição')
                                    ->columnSpanFull()
                                    ->disk(fn (?Model $record): string => $record instanceof MeasurementAsset
                                        ? $record->resolved_storage_disk
                                        : DocumentStorageService::privateDisk())
                                    ->directory(DocumentStorageService::PRIVATE_PREFIX.'/measurements/assets')
                                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(MeasurementFileValidationService::class)->storeAsset($file))
                                    // O caminho no estado do Livewire é controlado por quem
                                    // envia a requisição: sem esta trava, trocá-lo vinculava a
                                    // esta medição o arquivo de outra operação ou de outro
                                    // módulo do mesmo disco. Vale o caminho já gravado no
                                    // arquivo e o que esta requisição acabou de gravar -- o
                                    // Repeater com relationship valida o item de novo depois do
                                    // upload, e a trava sem exceção recusaria o envio legítimo.
                                    ->preventFilePathTampering(
                                        allowFilePathUsing: fn (string $file): bool => app(MeasurementFileValidationService::class)->wasStoredDuringThisRequest($file),
                                    )
                                    ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames, ?Model $record): ?array => static::uploadedFileForBrowser($component, $file, $storedFileNames, $record))
                                    ->acceptedFileTypes((array) config('uploads.measurement.allowed_mimes', ['application/pdf']))
                                    ->maxSize((int) config('uploads.measurement.max_kb', 51200))
                                    ->required()
                                    ->helperText('Formato PDF ou documento aprovado (máx. 50 MB).')
                                    ->validationMessages([
                                        'required' => 'Envie o arquivo da medição deste empreendimento.',
                                        'tampered' => 'O arquivo informado não pertence a esta medição. Envie o arquivo novamente.',
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Operações em que a pessoa pode enviar medição nova: em andamento e com
     * participação direta dela -- um dos sete papéis da operação --, ou
     * administrador do fluxo.
     *
     * É a regra de {@see MeasurementAuthorizationService::canCreateMeasurement()}
     * em SQL. Ver a operação por delegação não basta: o envio é de quem
     * participa diretamente, e o seletor oferecia ao delegado a operação que o
     * envio recusaria.
     *
     * @param  Builder<Operation>  $query
     * @return Builder<Operation>
     */
    protected static function scopeOpenToNewMeasurementBy(Builder $query, User $user): Builder
    {
        $query->where('operations.status', OperationStatus::Active->value);

        if (app(MeasurementAuthorizationService::class)->isWorkflowAdministrator($user)) {
            return $query;
        }

        return $query->where(function (Builder $participation) use ($user): void {
            foreach (Operation::RESPONSIBILITY_FIELDS as $field) {
                $participation->orWhere("operations.{$field}", $user->getKey());
            }
        });
    }

    /**
     * A medição do formulário -- ou a dona do arquivo do item -- já tem
     * pagamento registrado? Na criação não há registro, e a resposta é não.
     */
    protected static function hasRegisteredPayment(?Model $record): bool
    {
        $measurement = match (true) {
            $record instanceof Measurement => $record,
            $record instanceof MeasurementAsset => $record->measurement,
            default => null,
        };

        return $measurement instanceof Measurement
            && $measurement->exists
            && $measurement->hasRegisteredPayment();
    }

    /**
     * O que o campo de upload entrega ao navegador sobre um arquivo do estado.
     *
     * O padrão do Filament monta o endereço pelo disco: no disco privado com
     * URL temporária (Azure Blob) sai um link SAS válido até o fim da hora
     * seguinte, transferível e sem trilha; no legado público, a URL de
     * `/storage`. O arquivo da Medição só sai pelo controller da medição, que
     * autoriza, registra o acesso e aplica a CSP de download -- então o
     * endereço é a rota autorizada do próprio arquivo e nenhum outro, e o disco
     * nunca é consultado para montar URL. Um caminho que não é o gravado no
     * registro (o recém-enviado, ainda não salvo) fica sem endereço.
     *
     * @param  string|array<string, string>|null  $storedFileNames
     * @return array{name: string, size: int, type: string|null, url: string|null}|null
     */
    protected static function uploadedFileForBrowser(FileUpload $component, string $file, string|array|null $storedFileNames, ?Model $record): ?array
    {
        $size = 0;
        $type = null;

        if ($component->shouldFetchFileInformation()) {
            try {
                $storage = $component->getDisk();
                $size = $storage->size($file);
                $type = $storage->mimeType($file) ?: null;
            } catch (UnableToRetrieveMetadata|UnableToCheckFileExistence) {
                return null;
            }
        }

        $isSavedFile = $record instanceof MeasurementAsset
            && $record->exists
            && $file === $record->storage_path;

        return [
            'name' => (is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
            'size' => $size,
            'type' => $type,
            'url' => $isSavedFile ? route('admin.measurements.assets.download', $record) : null,
        ];
    }

    /**
     * Builds one asset row per development of the operation, with the plan set
     * pre-filled and the file/measurement left blank to fill. Só os planos
     * vigentes (com versão ativada): o plano ainda em rascunho não recebe
     * medição. Com a competência já escolhida, só os que preveem medição nela
     * ({@see self::assetsForCompetence()}).
     *
     * @return array<int|string, array<string, mixed>>
     */
    protected static function assetsForOperation(mixed $operationId, mixed $competence = null): array
    {
        return static::assetsForCompetence($operationId, $competence, []);
    }

    /**
     * Os arquivos do envio para a competência: um por empreendimento em vigor
     * que prevê medição nela, pela versão do plano que a rege -- é o que a
     * Engenharia vai exigir ({@see MeasurementEngineeringService}).
     * O empreendimento sem medição prevista na competência (a obra que começa
     * depois, ou que já terminou) sai sozinho, em vez de a pessoa ter de
     * removê-lo; o que volta a prever medição volta à lista.
     *
     * O que já foi preenchido continua: a mesma chave, o mesmo arquivo. Só a
     * medição prevista de outro mês é limpa -- a competência da medição é uma
     * só. Sem competência, vale a lista dos planos em vigor.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int|string, array<string, mixed>>
     */
    public static function assetsForCompetence(mixed $operationId, mixed $competence, array $rows): array
    {
        $planSetIds = static::planSetIdsInForce($operationId);
        $month = null;

        if (filled($competence)) {
            $start = MeasurementPlanVersionResolver::competenceStart($competence instanceof DateTimeInterface ? $competence : (string) $competence);
            $month = $start->format('Y-m');
            $planSetIds = app(MeasurementPlanVersionResolver::class)->planSetsPlannedIn($planSetIds, $start);
        }

        $chosenLineMonths = $month === null ? collect() : static::visiblePlanLinesQuery()
            ->whereKey(collect($rows)->pluck('plan_line_id')->filter()->all())
            ->get(['id', 'measurement_date'])
            ->mapWithKeys(fn (MeasurementPlanLine $line): array => [(int) $line->getKey() => $line->measurement_date?->format('Y-m')]);
        $kept = [];

        foreach ($rows as $key => $row) {
            if (! is_array($row) || ! in_array((int) ($row['plan_set_id'] ?? 0), $planSetIds, true)) {
                continue;
            }

            if ($month !== null && filled($row['plan_line_id'] ?? null) && $chosenLineMonths->get((int) $row['plan_line_id']) !== $month) {
                $row['plan_line_id'] = null;
            }

            $kept[$key] = $row;
        }

        $present = collect($kept)->map(fn (array $row): int => (int) ($row['plan_set_id'] ?? 0));

        // Linha nova ganha chave nova: reaproveitar a de uma linha que saiu
        // levaria para outro empreendimento o upload ainda em andamento nela.
        foreach ($planSetIds as $planSetId) {
            if (! $present->contains($planSetId)) {
                $kept[(string) Str::uuid()] = [
                    'plan_set_id' => $planSetId,
                    'plan_line_id' => null,
                    'storage_path' => null,
                ];
            }
        }

        uasort($kept, fn (array $a, array $b): int => (int) ($a['plan_set_id'] ?? 0) <=> (int) ($b['plan_set_id'] ?? 0));

        return $kept;
    }

    /**
     * Por que o envio ficou sem arquivo nenhum: nenhum plano em vigor, ou
     * nenhum que preveja medição na competência escolhida.
     */
    protected static function noDevelopmentMessage(mixed $operationId, mixed $competence): string
    {
        if (filled($competence) && static::planSetIdsInForce($operationId) !== []) {
            return sprintf(
                'Nenhum empreendimento desta operação prevê medição em %s: escolha outra competência.',
                MeasurementPlanVersionResolver::competenceStart($competence instanceof DateTimeInterface ? $competence : (string) $competence)->format('m/Y'),
            );
        }

        return 'Nenhum empreendimento desta operação tem plano de medição em vigor: ative a versão do plano (aba Versões dos Planos da operação) antes de enviar medição.';
    }

    /**
     * Planos da operação, visíveis para a pessoa, que estão em vigor (com
     * versão ativada), em ordem de id.
     *
     * @return list<int>
     */
    public static function planSetIdsInForce(mixed $operationId): array
    {
        if (blank($operationId)) {
            return [];
        }

        return static::visiblePlanSetsQuery()
            ->where('operation_id', $operationId)
            ->whereHas('activeVersion')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Quais planos da operação ainda não estão em vigor, para o aviso do envio;
     * `null` quando todos estão.
     */
    protected static function plansNotInForceNotice(mixed $operationId): ?string
    {
        if (blank($operationId)) {
            return null;
        }

        $drafts = static::visiblePlanSetsQuery()
            ->where('operation_id', $operationId)
            ->whereDoesntHave('activeVersion')
            ->with('construction')
            ->orderBy('id')
            ->get()
            ->map(fn (MeasurementPlanSet $planSet): string => $planSet->construction?->development_name ?? $planSet->name);

        return $drafts->isEmpty() ? null : sprintf(
            'Ainda sem plano de medição em vigor, não recebe%s medição neste envio: %s. Ative a versão do plano na aba Versões dos Planos da operação.',
            $drafts->count() === 1 ? '' : 'm',
            $drafts->implode(', '),
        );
    }

    /**
     * Medições previstas de um empreendimento (plano) que ainda podem receber o
     * arquivo, indexadas pelo id da linha.
     *
     * Só entram as linhas que a Engenharia ainda aceitaria
     * ({@see MeasurementPlanLine::scopeAvailableForMeasurement()}): a linha
     * reivindicada por outra aprovação vigente, ou ocupada por outra medição,
     * seria recusada lá na frente. Como o Select valida o valor contra estas
     * opções, a linha que saiu da lista também é recusada no envio. Na edição,
     * os arquivos da própria medição não ocupam a linha, e a que já está gravada
     * no item continua listada -- o mesmo cuidado do select de Operação.
     *
     * A competência coberta pelo avanço físico inicial continua oferecida: nela
     * a Engenharia aceita 0%, e numa operação com vários empreendimentos esse 0%
     * é o que deixa medir os demais.
     *
     * Cada competência aparece pela versão do plano que a rege
     * ({@see MeasurementPlanLine::scopeGoverningTheirCompetence()}): junho
     * pela linha da V1 mesmo depois de a V2 valer desde julho -- e não pela
     * cópia de junho que a V2 traz --, julho em diante pela V2. É a linha que
     * a medição congela no envio. Na edição, só as competências que a versão
     * congelada no arquivo rege. A competência que a operação já mediu sem
     * este plano também fica de fora
     * ({@see MeasurementPlanLine::scopeCompetenceMeasuredWithoutThePlan()}).
     *
     * @return array<int, string>
     */
    protected static function scheduleOptionsForPlanSet(mixed $planSetId, ?MeasurementAsset $asset = null): array
    {
        if (blank($planSetId)) {
            return [];
        }

        $editedMeasurementId = filled($asset?->measurement_id) ? (int) $asset->measurement_id : null;
        $savedLineId = $asset?->plan_line_id;
        // A versão congelada é a da linha do arquivo; sem linha, nada congelou.
        $frozenVersionId = filled($asset?->plan_line_id) && filled($asset?->plan_version_id) ? (int) $asset->plan_version_id : null;

        return static::visiblePlanLinesQuery()
            ->where('plan_set_id', $planSetId)
            ->when($frozenVersionId !== null, fn (Builder $lines): Builder => $lines->where('plan_version_id', $frozenVersionId))
            ->where(fn (Builder $lines): Builder => $lines
                ->where(fn (Builder $offered): Builder => $offered
                    ->governingTheirCompetence()
                    ->availableForMeasurement($editedMeasurementId)
                    ->whereNot(fn (Builder $orphans): Builder => $orphans->competenceMeasuredWithoutThePlan()))
                ->when(filled($savedLineId), fn (Builder $saved): Builder => $saved->orWhereKey($savedLineId)))
            ->orderBy('sequence_number')
            ->orderBy('measurement_date')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (MeasurementPlanLine $line): array {
                $num = str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT);
                $month = $line->measurement_date?->format('m/Y') ?? '—';
                $planned = filled($line->planned_cumulative_percent)
                    ? ' · '.number_format((float) $line->planned_cumulative_percent, 1, ',', '.').'% planejado'
                    : '';

                return [$line->id => "Medição {$num} · {$month}{$planned}"];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function planSetOptions(mixed $operationId): array
    {
        if (blank($operationId)) {
            return [];
        }

        return static::visiblePlanSetsQuery()
            ->where('operation_id', $operationId)
            // Plano vigente: uma vez ativado, ele sempre tem uma versão vigente.
            ->whereHas('activeVersion')
            ->with('construction')
            ->get()
            ->mapWithKeys(fn (MeasurementPlanSet $planSet): array => [
                $planSet->id => $planSet->construction?->development_name ?? $planSet->name,
            ])
            ->all();
    }

    protected static function planSetLabel(mixed $planSetId): ?string
    {
        $planSet = static::visiblePlanSetsQuery()->with('construction')->find($planSetId);

        return $planSet?->construction?->development_name ?? $planSet?->name;
    }

    protected static function visiblePlanSetsQuery(): Builder
    {
        $user = auth()->user();

        return MeasurementPlanSet::query()
            ->whereHas('operation', fn (Builder $operations): Builder => $user === null
                ? $operations->whereRaw('1 = 0')
                : $operations->visibleTo($user));
    }

    protected static function visiblePlanLinesQuery(): Builder
    {
        $user = auth()->user();

        return MeasurementPlanLine::query()
            ->whereHas('operation', fn (Builder $operations): Builder => $user === null
                ? $operations->whereRaw('1 = 0')
                : $operations->visibleTo($user));
    }
}
