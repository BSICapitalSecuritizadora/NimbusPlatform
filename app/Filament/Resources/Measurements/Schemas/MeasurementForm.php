<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Enums\MeasurementPlanVersionStatus;
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
use App\Services\MeasurementFileValidationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
                            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                                if ($state !== $old) {
                                    $set('assets', static::assetsForOperation($state));
                                    // Os planos em vigor no momento da escolha: o envio
                                    // confere sob o lock que eles continuam os mesmos
                                    // (CreateMeasurement::assertThePlansInForceDidNotChange()).
                                    $set('offered_plan_set_ids', static::planSetIdsInForce($state));
                                }
                            })
                            ->validationMessages([
                                'required' => 'Selecione uma operação.',
                            ]),

                        // Com pagamento registrado, a competência fica presa ao
                        // que foi pago (Measurement::PAID_COMPETENCE_CHANGE_REFUSAL):
                        // o campo travado diz isso antes de a gravação recusar.
                        DatePicker::make('reference_month')
                            ->label('Competência')
                            ->placeholder('mm/aaaa')
                            ->displayFormat('m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->disabled(fn (?Model $record): bool => static::hasRegisteredPayment($record))
                            ->helperText(fn (?Model $record): string => static::hasRegisteredPayment($record)
                                ? 'Travada: esta medição tem pagamento registrado, e o pagamento continua vinculado a esta competência.'
                                : 'Preenchida automaticamente pela medição selecionada no cronograma.')
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
                        Hidden::make('offered_plan_set_ids')
                            ->dehydrated(false),

                        // Plano ainda em rascunho não recebe medição: sem o aviso, a
                        // operação escolhida mostrava a seção vazia e o envio só
                        // respondia com o erro genérico do repeater.
                        Placeholder::make('plans_not_in_force_notice')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => static::plansNotInForceNotice($get('operation_id')) ?? '')
                            ->visible(fn (Get $get, ?Model $record): bool => ! $record instanceof Measurement
                                && filled($get('operation_id'))
                                && static::plansNotInForceNotice($get('operation_id')) !== null),

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
                            // O arquivo de medição paga não sai mais
                            // (MeasurementAsset::PAID_FILE_REMOVAL_REFUSAL): a
                            // Engenharia dela confere justamente os
                            // empreendimentos destes arquivos.
                            ->deletable(fn (?Model $record): bool => ! static::hasRegisteredPayment($record))
                            ->deleteAction(fn (Action $action) => $action->tooltip('Remover empreendimento deste envio'))
                            ->reorderable(false)
                            ->minItems(1)
                            ->validationMessages([
                                'min' => 'Nenhum empreendimento desta operação tem plano de medição em vigor: ative a versão do plano (aba Versões dos Planos da operação) antes de enviar medição.',
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
                                    // (MeasurementAsset::PAID_CONTEXT_CHANGE_REFUSAL), e a
                                    // do arquivo enviado sob uma versão do plano já
                                    // substituída (SUPERSEDED_VERSION_LINE_CHANGE_REFUSAL).
                                    // A da versão é a de quando a página abriu: substituída
                                    // no meio-tempo, a troca chega à gravação e é recusada,
                                    // em vez de sumir calada enquanto a competência muda.
                                    ->disabled(fn (Get $get, ?Model $record): bool => static::hasRegisteredPayment($record) || (bool) $get('sent_under_superseded_version'))
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $date = filled($state)
                                            ? static::visiblePlanLinesQuery()->whereKey($state)->value('measurement_date')
                                            : null;

                                        if (filled($date)) {
                                            $set('../../reference_month', Carbon::parse($date)->toDateString());
                                        }
                                    })
                                    ->helperText(fn (Get $get, ?Model $record): string => match (true) {
                                        static::hasRegisteredPayment($record) => 'Travada: esta medição tem pagamento registrado, e o pagamento continua vinculado a esta linha.',
                                        (bool) $get('sent_under_superseded_version') => 'Travada: a medição foi enviada sob uma versão do plano que já foi substituída. Para medir outra competência, recuse-a e envie uma nova sob a versão vigente.',
                                        default => 'Selecione a medição prevista à qual este arquivo corresponde.',
                                    })
                                    ->validationMessages([
                                        'required' => 'Selecione a medição do cronograma correspondente.',
                                    ]),

                                Hidden::make('sent_under_superseded_version')
                                    ->dehydrated(false)
                                    ->afterStateHydrated(fn (Hidden $component, ?Model $record) => $component->state(static::wasSentUnderSupersededVersion($record))),

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
     * O arquivo foi enviado sob uma versão do plano que outra já substituiu?
     * A medição continua nela, e a linha não muda mais.
     */
    protected static function wasSentUnderSupersededVersion(?Model $record): bool
    {
        return $record instanceof MeasurementAsset
            && $record->exists
            && filled($record->plan_version_id)
            && MeasurementPlanVersion::query()->whereKey($record->plan_version_id)->value('status') === MeasurementPlanVersionStatus::Superseded;
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
     * medição.
     *
     * @return array<int, array{plan_set_id: int, plan_line_id: null, storage_path: null}>
     */
    protected static function assetsForOperation(mixed $operationId): array
    {
        return collect(static::planSetIdsInForce($operationId))
            ->map(fn (int $id): array => [
                'plan_set_id' => $id,
                'plan_line_id' => null,
                'storage_path' => null,
            ])
            ->all();
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
     * As linhas são as da versão do plano: a vigente, no envio; na edição, a
     * versão em que a medição foi enviada, que ela leva para sempre -- mesmo
     * que outra versão tenha sido ativada depois.
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
        $versionId = filled($asset?->plan_version_id)
            ? (int) $asset->plan_version_id
            : MeasurementPlanVersion::query()->where('plan_set_id', $planSetId)->active()->value('id');

        if ($versionId === null) {
            return [];
        }

        return static::visiblePlanLinesQuery()
            ->where('plan_set_id', $planSetId)
            ->where('plan_version_id', $versionId)
            ->whereNotNull('measurement_date')
            ->where(fn (Builder $lines): Builder => $lines
                ->where(fn (Builder $available): Builder => $available->availableForMeasurement($editedMeasurementId))
                ->when(filled($savedLineId), fn (Builder $saved): Builder => $saved->orWhereKey($savedLineId)))
            ->orderBy('sequence_number')
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
