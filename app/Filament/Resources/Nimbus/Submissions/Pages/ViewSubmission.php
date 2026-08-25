<?php

namespace App\Filament\Resources\Nimbus\Submissions\Pages;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\Nimbus\Submission;
use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Nimbus\SubmissionWorkflowService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('alterar_situacao')
                ->label('Alterar Situação')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (Submission $record): bool => ! $record->isFinalStatus())
                ->modalHeading('Alterar Situação')
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (Submission $record): array => [
                    'status' => $record->status,
                    'visibility' => 'USER_VISIBLE',
                    'note' => null,
                ])
                ->form([
                    Select::make('status')
                        ->label('Nova Situação')
                        ->options([
                            Submission::STATUS_PENDING => 'Pendente',
                            Submission::STATUS_UNDER_REVIEW => 'Em Análise',
                            Submission::STATUS_NEEDS_CORRECTION => 'Aguardando Correção',
                            Submission::STATUS_COMPLETED => 'Aprovado / Concluído',
                            Submission::STATUS_REJECTED => 'Rejeitado',
                        ])
                        ->required(),
                    Select::make('visibility')
                        ->label('Visibilidade da Observação')
                        ->options([
                            'USER_VISIBLE' => 'Visível ao solicitante no Portal',
                            'ADMIN_ONLY' => 'Apenas interna para administradores',
                        ])
                        ->default('USER_VISIBLE')
                        ->required(),
                    Textarea::make('note')
                        ->label('Observação / Comentário (opcional)')
                        ->placeholder('Adicione um comentário ou detalhe o que precisa ser corrigido pelo usuário...')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->extraModalFooterActions(fn (Actions\Action $action): array => [
                    $action->makeModalSubmitAction('aprovar', arguments: ['intent' => 'approve'])
                        ->label('Aprovar')
                        ->icon('heroicon-o-check')
                        ->color('success'),
                    $action->makeModalSubmitAction('solicitar_correcao', arguments: ['intent' => 'request_correction'])
                        ->label('Solicitar Correção')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->outlined()
                        ->visible(fn (Submission $record): bool => app(SubmissionWorkflowService::class)->isAllowedTransition($record->status, Submission::STATUS_NEEDS_CORRECTION)),
                    $action->makeModalSubmitAction('rejeitar', arguments: ['intent' => 'reject'])
                        ->label('Rejeitar')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->outlined(),
                    $action->makeModalSubmitAction('comentario_interno', arguments: ['intent' => 'internal_comment'])
                        ->label('Enviar Comentário Interno')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('warning'),
                ])
                ->action(function (array $data, array $arguments, Submission $record): void {
                    $intent = $arguments['intent'] ?? 'update_status';
                    $note = trim((string) ($data['note'] ?? ''));
                    // Server-side guarantee: correction reason must always be USER_VISIBLE,
                    // internal comments must always be ADMIN_ONLY, regardless of UI input or manipulation.
                    $visibility = match ($intent) {
                        'request_correction' => 'USER_VISIBLE',
                        'internal_comment' => 'ADMIN_ONLY',
                        default => in_array(($data['visibility'] ?? 'USER_VISIBLE'), ['USER_VISIBLE', 'ADMIN_ONLY'], true)
                            ? $data['visibility']
                            : 'USER_VISIBLE',
                    };

                    $requestedStatus = match ($intent) {
                        'approve' => Submission::STATUS_COMPLETED,
                        'request_correction' => Submission::STATUS_NEEDS_CORRECTION,
                        'reject' => Submission::STATUS_REJECTED,
                        'internal_comment' => $record->status,
                        default => $data['status'] ?? $record->status,
                    };

                    $status = Submission::persistableStatusFor($requestedStatus);
                    $usedLegacyCorrectionFallback = ($requestedStatus === Submission::STATUS_NEEDS_CORRECTION)
                        && ($status !== Submission::STATUS_NEEDS_CORRECTION);

                    if (! array_key_exists($status, Submission::statusOptions())) {
                        throw ValidationException::withMessages([
                            'status' => 'Selecione uma situação válida.',
                        ]);
                    }

                    if (($intent === 'request_correction') && ($note === '')) {
                        throw ValidationException::withMessages([
                            'note' => 'Você precisa escrever uma observação detalhando o que deve ser corrigido pelo usuário.',
                        ]);
                    }

                    $actor = auth()->user();

                    // Internal comment: no status transition, only note.
                    if ($intent === 'internal_comment') {
                        if ($note !== '') {
                            $record->notes()->create([
                                'user_id' => auth()->id(),
                                'visibility' => $visibility,
                                'message' => $note,
                            ]);

                            activity('nimbus')
                                ->performedOn($record)
                                ->causedBy($actor)
                                ->withProperties(['visibility' => $visibility])
                                ->log('nimbus.submission.internal_note');
                        }

                        // No status history for internal comment.
                    } else {
                        $workflow = app(SubmissionWorkflowService::class);

                        // request_correction must be atomic: transition + USER_VISIBLE note + audits succeed together,
                        // otherwise no partial state (status without visible instruction).
                        if ($intent === 'request_correction') {
                            DB::transaction(function () use ($workflow, $record, $status, $actor, $note, $visibility): void {
                                if ($status !== $record->status) {
                                    $workflow->transition($record, $status, $actor, $note !== '' ? $note : null);
                                }

                                if ($note !== '') {
                                    $record->notes()->create([
                                        'user_id' => auth()->id(),
                                        'visibility' => $visibility,
                                        'message' => $note,
                                    ]);

                                    activity('nimbus')
                                        ->performedOn($record)
                                        ->causedBy($actor)
                                        ->withProperties(['visibility' => $visibility])
                                        ->log('nimbus.submission.correction_requested');
                                }
                            });

                            // Enqueue transactional notification only after commit.
                            try {
                                $fresh = $record->refresh();
                                $historyId = $fresh->statusHistories()->where('old_status', Submission::STATUS_UNDER_REVIEW)->where('new_status', Submission::STATUS_NEEDS_CORRECTION)->reorder()->orderByDesc('id')->first()?->id;
                                app(NimbusNotificationService::class)->enqueueCorrectionRequested($fresh, $note, $historyId);
                            } catch (\Throwable $e) {
                            }
                        } else {
                            $didTransition = false;
                            // Centralized transition creates history + audit atomically.
                            // Workaround for legacy enum fallback is preserved via persistableStatusFor above.
                            if ($status !== $record->status) {
                                $workflow->transition($record, $status, $actor, $note !== '' ? $note : null);
                                $didTransition = true;
                            }

                            if ($note !== '') {
                                $record->notes()->create([
                                    'user_id' => auth()->id(),
                                    'visibility' => $visibility,
                                    'message' => $note,
                                ]);

                                activity('nimbus')
                                    ->performedOn($record)
                                    ->causedBy($actor)
                                    ->withProperties(['visibility' => $visibility])
                                    ->log('nimbus.submission.note_added');
                            }

                            // Enqueue status-change notifications after commit.
                            try {
                                $fresh = $record->refresh();
                                $notifier = app(NimbusNotificationService::class);
                                if ($didTransition) {
                                    if ($fresh->status === Submission::STATUS_COMPLETED && $intent === 'approve') {
                                        $notifier->enqueueCompleted($fresh);
                                    } elseif ($fresh->status === Submission::STATUS_REJECTED && $intent === 'reject') {
                                        $notifier->enqueueRejected($fresh);
                                    }
                                }
                            } catch (\Throwable $e) {
                            }
                        }
                    }

                    $title = match ($intent) {
                        'approve' => 'Envio aprovado com sucesso.',
                        'request_correction' => 'Correção solicitada com sucesso.',
                        'reject' => 'Envio rejeitado com sucesso.',
                        'internal_comment' => 'Comentário interno registrado com sucesso.',
                        default => 'Situação atualizada com sucesso.',
                    };

                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($usedLegacyCorrectionFallback
                            ? 'A coluna de status ainda usa o enum antigo. A solicitação foi registrada como Em Análise até a migration de status ser aplicada.'
                            : null)
                        ->send();
                }),
        ];
    }
}
