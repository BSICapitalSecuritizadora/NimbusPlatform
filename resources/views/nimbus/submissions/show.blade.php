@extends('nimbus.layouts.portal')

@section('title', 'Detalhes da Solicitação')

@section('content')
@php
    $requestFiles = $submission->userUploadedFiles;
    $responseFiles = $submission->portalVisibleResponseFiles;
    $portalVisibleNotes = $submission->portalVisibleNotes;
    $nimbusUser = auth('nimbus')->user();
    $submittedAt = $submission->submitted_at?->timezone('America/Sao_Paulo');
    $statusConfig = [
        'label' => \App\Models\Nimbus\Submission::statusLabelFor($submission->status),
        'class' => \App\Models\Nimbus\Submission::statusColorFor($submission->status),
        'icon' => \App\Models\Nimbus\Submission::statusIconFor($submission->status),
    ];
    $operationLabels = [
        'REGISTRATION' => 'Cadastro',
    ];
    $operationLabel = filled($submission->submission_type)
        ? ($operationLabels[$submission->submission_type] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', mb_strtolower((string) $submission->submission_type))))
        : 'Operação em análise';
    $protocol = $submission->reference_code ?: sprintf(
        'BSI-%s-%04d',
        $submittedAt?->format('Y') ?? now()->format('Y'),
        $submission->id,
    );
    $shareholderCount = $submission->shareholders->count();
    $isCorrectionPending = $submission->status === \App\Models\Nimbus\Submission::STATUS_NEEDS_CORRECTION;
    $formatDateTime = static fn ($date): string => $date?->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') ?? '-';
    $formatCurrency = static function (mixed $amount): string {
        if ($amount === null || $amount === '') {
            return 'Não informado';
        }

        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    };
    $complianceDeclarations = [
        [
            'label' => 'Sou US Person',
            'active' => (bool) $submission->is_us_person,
        ],
        [
            'label' => 'Sou PEP (Pessoa Exposta Politicamente)',
            'active' => (bool) $submission->is_pep,
        ],
        [
            'label' => 'Não me enquadro nas opções',
            'active' => ! $submission->is_us_person && ! $submission->is_pep,
        ],
    ];
    $resolveFileIcon = static function (?string $mimeType): string {
        $mimeType = mb_strtolower((string) $mimeType);

        return match (true) {
            str_contains($mimeType, 'pdf') => 'bi-filetype-pdf',
            str_contains($mimeType, 'image') => 'bi-file-earmark-image',
            str_contains($mimeType, 'zip') => 'bi-file-earmark-zip',
            str_contains($mimeType, 'sheet'), str_contains($mimeType, 'excel') => 'bi-file-earmark-spreadsheet',
            str_contains($mimeType, 'word') => 'bi-file-earmark-word',
            default => 'bi-file-earmark-text',
        };
    };
@endphp

<div class="mb-8">
    <div class="mb-6 flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex items-start gap-4">
            <a href="{{ route('nimbus.submissions.index') }}" class="submission-back-button" aria-label="Voltar para meus envios">
                <i class="bi bi-arrow-left text-[15px]"></i>
            </a>
            <div>
                <div class="font-jetbrains mb-3 text-[11px] uppercase tracking-[.18em] text-gold-500">ACESSO EXTERNO · DETALHE DA SOLICITAÇÃO</div>
                <h1 class="font-fraunces mb-2 text-[34px] font-medium leading-tight text-navy-900">{{ $protocol }}</h1>
                <p class="font-inter text-[14.5px] text-ink-500">
                    Enviada em {{ $submittedAt?->format('d/m/Y \à\s H:i') ?? '-' }} · {{ $operationLabel }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 xl:justify-end">
            <div class="submission-meta-pill">Protocolo · {{ $protocol }}</div>
            <div class="submission-meta-pill submission-meta-pill-muted">{{ $requestFiles->count() }} anexos enviados</div>
            <div class="submission-status-pill {{ $statusConfig['class'] }}">
                <i class="bi {{ $statusConfig['icon'] }}"></i>
                {{ $statusConfig['label'] }}
            </div>
        </div>
    </div>

    <div class="mb-8 overflow-hidden rounded-[8px] border border-ink-200 bg-white shadow-portal-subtle">
        <div class="grid grid-cols-2 divide-x divide-y divide-ink-100 lg:grid-cols-4 lg:divide-y-0">
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Status Atual</div>
                <div class="font-fraunces text-[24px] font-medium leading-none text-navy-900">{{ $statusConfig['label'] }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">{{ $isCorrectionPending ? 'Há ajustes aguardando retorno.' : 'Fluxo em acompanhamento pela equipe.' }}</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Mensagens</div>
                <div class="font-fraunces text-[24px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $portalVisibleNotes->count()) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">Orientações compartilhadas com você no portal.</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Arquivos Enviados</div>
                <div class="font-fraunces text-[24px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $requestFiles->count()) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">Documentos anexados no envio ou em correções.</div>
            </div>
            <div class="p-6">
                <div class="font-inter mb-2 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400">Retornos Disponíveis</div>
                <div class="font-fraunces text-[24px] font-medium leading-none text-navy-900">{{ sprintf('%02d', $responseFiles->count()) }}</div>
                <div class="font-inter mt-3 text-[12px] text-ink-500">Arquivos liberados pela mesa operacional.</div>
            </div>
        </div>
    </div>

    @if ($isCorrectionPending)
        <div class="submission-callout mb-8">
            <div class="submission-callout-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="flex-1">
                <div class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Ação Necessária</div>
                <p class="font-inter mb-0 text-[13.5px] leading-relaxed text-ink-600">
                    Foram solicitadas correções nesta submissão. Revise as orientações da equipe e responda abaixo com um comentário, um novo arquivo ou ambos.
                </p>
            </div>
            <a href="#reply-form" class="submission-callout-link">
                <span>Ir para resposta</span>
                <i class="bi bi-arrow-down"></i>
            </a>
        </div>
    @endif

    <div class="row g-6">
        <div class="col-xl-8">
            <div class="submission-panel mb-6">
                <div class="submission-panel-header">
                    <div>
                        <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Dados Gerais</h2>
                        <div class="font-jetbrains text-[11px] uppercase tracking-[.1em] text-ink-400">Resumo da solicitação</div>
                    </div>
                    <div class="submission-count-pill">{{ $operationLabel }}</div>
                </div>

                <div class="submission-panel-body">
                    <div class="space-y-6">
                        <div>
                            <div class="submission-section-heading">Controle do envio</div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Operação</div>
                                    <div class="submission-data-value">{{ $operationLabel }}</div>
                                    <div class="submission-data-meta">{{ $submission->title ?? 'Solicitação enviada' }}</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Status</div>
                                    <div class="submission-data-value">{{ $statusConfig['label'] }}</div>
                                    <div class="submission-data-meta">Etapa atual do fluxo com a equipe</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Enviada em</div>
                                    <div class="submission-data-value">{{ $submittedAt?->format('d/m/Y') ?? '-' }}</div>
                                    <div class="submission-data-meta">{{ $submittedAt?->format('H:i') ?? 'Sem horário' }} · São Paulo</div>
                                </div>
                            </div>
                        </div>

                        <div class="submission-section-block">
                            <div class="submission-section-heading">Dados da Empresa</div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Empresa</div>
                                    <div class="submission-data-value">{{ $submission->company_name ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">{{ $submission->company_cnpj ?? 'CNPJ não informado' }}</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Responsável</div>
                                    <div class="submission-data-value">{{ $submission->responsible_name ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Contato principal informado no envio</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Telefone</div>
                                    <div class="submission-data-value">{{ $submission->phone ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Canal de contato da empresa</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Site</div>
                                    @if (filled($submission->website))
                                        <a href="{{ $submission->website }}" target="_blank" rel="noopener noreferrer" class="submission-data-link">{{ $submission->website }}</a>
                                    @else
                                        <div class="submission-data-value">Não informado</div>
                                    @endif
                                    <div class="submission-data-meta">Endereço institucional informado no cadastro</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Patrimônio Líquido</div>
                                    <div class="submission-data-value">{{ $formatCurrency($submission->net_worth) }}</div>
                                    <div class="submission-data-meta">Valor declarado pela empresa</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Último Faturamento Anual</div>
                                    <div class="submission-data-value">{{ $formatCurrency($submission->annual_revenue) }}</div>
                                    <div class="submission-data-meta">Último faturamento informado no envio</div>
                                </div>
                            </div>
                        </div>

                        <div class="submission-section-block">
                            <div class="submission-section-heading">Dados do Responsável pelo Cadastro</div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Nome Completo</div>
                                    <div class="submission-data-value">{{ $submission->registrant_name ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Pessoa que realizou o cadastro</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Cargo</div>
                                    <div class="submission-data-value">{{ $submission->registrant_position ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Função declarada na empresa</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">RG</div>
                                    <div class="submission-data-value">{{ $submission->registrant_rg ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Documento de identificação</div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">CPF</div>
                                    <div class="submission-data-value">{{ $submission->registrant_cpf ?? 'Não informado' }}</div>
                                    <div class="submission-data-meta">Cadastro de pessoa física</div>
                                </div>
                            </div>
                        </div>

                        <div class="submission-section-block">
                            <div class="submission-section-heading">Declarações</div>
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Perfil Declarado</div>
                                    <div class="submission-flag-list">
                                        @foreach ($complianceDeclarations as $declaration)
                                            <div class="submission-flag-chip {{ $declaration['active'] ? 'is-active' : '' }}">
                                                {{ $declaration['label'] }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="submission-data-card">
                                    <div class="submission-data-label">Você é filiado à Anbima?</div>
                                    <div class="submission-flag-list">
                                        <div class="submission-flag-chip {{ $submission->is_anbima_affiliated === true ? 'is-active is-positive' : '' }}">
                                            Sim
                                        </div>
                                        <div class="submission-flag-chip {{ $submission->is_anbima_affiliated === false ? 'is-active' : '' }}">
                                            Não
                                        </div>
                                    </div>
                                    @if ($submission->is_anbima_affiliated === null)
                                        <div class="submission-data-meta">Informação não declarada no cadastro.</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="submission-panel mb-6">
                <div class="submission-panel-header">
                    <div>
                        <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Mensagens da Equipe</h2>
                        <div class="font-inter text-[13px] text-ink-500">Orientações e comentários compartilhados com você sobre esta solicitação.</div>
                    </div>
                    <div class="submission-count-pill">{{ $portalVisibleNotes->count() }}</div>
                </div>

                <div class="submission-panel-body p-0">
                    @forelse ($portalVisibleNotes as $note)
                        <div class="submission-message {{ $loop->last ? '' : 'border-b border-ink-100' }}">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-inter text-[13px] font-semibold text-navy-900">{{ $note->user?->name ?? 'Equipe BSI Capital' }}</span>
                                    <span class="submission-note-pill">Mensagem</span>
                                </div>
                                <div class="font-jetbrains text-[10px] uppercase tracking-[.1em] text-ink-400">{{ $formatDateTime($note->created_at) }}</div>
                            </div>
                            <div class="mt-3 whitespace-pre-line font-inter text-[13.5px] leading-relaxed text-ink-700">{{ $note->message }}</div>
                        </div>
                    @empty
                        <div class="submission-empty-state">
                            <div class="mb-3">
                                <i class="bi bi-chat-square-text text-3xl text-ink-200"></i>
                            </div>
                            <h3 class="font-fraunces mb-2 text-lg text-navy-900">Nenhuma orientação disponível</h3>
                            <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">Quando a equipe operacional registrar alguma observação visível no portal, ela aparecerá aqui.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            @if ($isCorrectionPending)
                <div id="reply-form" class="submission-panel submission-panel-highlight mb-6">
                    <div class="submission-panel-header">
                        <div>
                            <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Enviar Correção</h2>
                            <div class="font-inter text-[13px] text-ink-500">Responda à equipe com contexto do ajuste realizado e, se necessário, anexe um novo documento.</div>
                        </div>
                    </div>

                    <div class="submission-panel-body">
                        <form action="{{ route('nimbus.submissions.reply', $submission) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                            @csrf

                            <div>
                                <label for="comment" class="submission-field-label">Resposta / Comentário</label>
                                <textarea
                                    name="comment"
                                    id="comment"
                                    rows="5"
                                    class="submission-form-control @error('comment') border-rose-600 focus:border-rose-600 @enderror"
                                    placeholder="Descreva as correções feitas ou oriente a equipe sobre o novo envio."
                                >{{ old('comment') }}</textarea>
                                @error('comment')
                                    <div class="submission-field-error">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="file" class="submission-field-label">Documento Corrigido <span class="font-normal text-ink-400">(opcional)</span></label>
                                <input
                                    type="file"
                                    name="file"
                                    id="file"
                                    class="submission-file-input @error('file') border-rose-600 focus:border-rose-600 @enderror"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.jpg,.jpeg,.png"
                                >
                                <div class="submission-field-help">Formatos aceitos: PDF, DOC, DOCX, XLS, XLSX, ZIP e imagens.</div>
                                @error('file')
                                    <div class="submission-field-error">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="p-btn-primary inline-flex h-[46px] items-center justify-center gap-2 rounded-[5px] border border-gold-600 bg-gold-500 px-6 text-[13px] font-semibold text-white transition-all hover:bg-gold-400">
                                <i class="bi bi-send text-[12px]"></i>
                                Enviar Correção
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="submission-panel mb-6">
                <div class="submission-panel-header">
                    <div>
                        <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Composição Societária</h2>
                        <div class="font-inter text-[13px] text-ink-500">Estrutura societária informada no cadastro desta solicitação.</div>
                    </div>
                    <div class="submission-count-pill">{{ sprintf('%02d', $shareholderCount) }}</div>
                </div>

                <div class="overflow-x-auto">
                    @if ($submission->shareholders->isEmpty())
                        <div class="submission-empty-state border-t border-ink-100">
                            <div class="mb-3">
                                <i class="bi bi-diagram-3 text-3xl text-ink-200"></i>
                            </div>
                            <h3 class="font-fraunces mb-2 text-lg text-navy-900">Nenhum sócio registrado</h3>
                            <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">A composição societária não foi preenchida ou não possui registros disponíveis nesta solicitação.</p>
                        </div>
                    @else
                        <table class="submission-table w-full">
                            <thead>
                                <tr>
                                    <th class="px-7 py-3 text-left">Nome</th>
                                    <th class="px-7 py-3 text-left">Documento</th>
                                    <th class="px-7 py-3 text-right">Participação</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($submission->shareholders as $shareholder)
                                    <tr>
                                        <td class="px-7 py-4">
                                            <div class="font-inter text-[13px] font-medium text-navy-900">{{ $shareholder->name }}</div>
                                        </td>
                                        <td class="px-7 py-4">
                                            <div class="font-jetbrains text-[12px] text-ink-500">{{ $shareholder->document_rg ?? $shareholder->document_cnpj ?? '-' }}</div>
                                        </td>
                                        <td class="px-7 py-4 text-right">
                                            <div class="font-jetbrains text-[12px] font-semibold text-navy-900">{{ number_format($shareholder->percentage, 2, ',', '.') }}%</div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="nd-sticky-sidebar flex flex-col gap-6">
                <div class="submission-panel">
                    <div class="submission-panel-header">
                        <div>
                            <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Arquivos Anexos</h2>
                            <div class="font-inter text-[13px] text-ink-500">Documentos enviados por você no fluxo da solicitação.</div>
                        </div>
                        <div class="submission-count-pill">{{ sprintf('%02d', $requestFiles->count()) }}</div>
                    </div>

                    <div class="submission-panel-body {{ $requestFiles->isNotEmpty() ? 'submission-scroll-area' : '' }} p-0">
                        @forelse ($requestFiles as $file)
                            <div class="submission-file-row {{ $loop->last ? '' : 'border-b border-ink-100' }}">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div class="submission-file-icon submission-file-icon-user">
                                        <i class="bi {{ $resolveFileIcon($file->mime_type) }}"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate font-inter text-[13px] font-semibold text-navy-900">{{ $file->document_type_label }}</div>
                                        <div class="truncate font-inter text-[12px] text-ink-500">{{ $file->original_name }}</div>
                                        <div class="mt-1 font-jetbrains text-[10px] uppercase tracking-[.08em] text-ink-400">
                                            {{ \Illuminate\Support\Number::fileSize((int) $file->size_bytes) }} · {{ $file->uploaded_at?->format('d/m/Y') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                                @if (\Illuminate\Support\Facades\Gate::forUser($nimbusUser)->check('downloadFile', [$submission, $file]))
                                    <a href="{{ route('nimbus.submissions.files.download', [$submission, $file]) }}" class="doc-btn-square" title="Baixar arquivo enviado">
                                        <i class="bi bi-download"></i>
                                    </a>
                                @endif
                            </div>
                        @empty
                            <div class="submission-empty-state">
                                <div class="mb-3">
                                    <i class="bi bi-folder2-open text-3xl text-ink-200"></i>
                                </div>
                                <h3 class="font-fraunces mb-2 text-lg text-navy-900">Nenhum arquivo enviado</h3>
                                <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">Os anexos da sua solicitação aparecerão aqui conforme forem enviados.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="submission-panel">
                    <div class="submission-panel-header">
                        <div>
                            <h2 class="font-fraunces mb-1 text-[22px] font-medium text-navy-900">Documentos de Retorno</h2>
                            <div class="font-inter text-[13px] text-ink-500">Arquivos disponibilizados pela equipe para download.</div>
                        </div>
                        <div class="submission-count-pill">{{ sprintf('%02d', $responseFiles->count()) }}</div>
                    </div>

                    <div class="submission-panel-body {{ $responseFiles->isNotEmpty() ? 'submission-scroll-area' : '' }} p-0">
                        @forelse ($responseFiles as $file)
                            <div class="submission-file-row {{ $loop->last ? '' : 'border-b border-ink-100' }}">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div class="submission-file-icon submission-file-icon-response">
                                        <i class="bi {{ $resolveFileIcon($file->mime_type) }}"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate font-inter text-[13px] font-semibold text-navy-900">{{ $file->original_name }}</div>
                                        <div class="font-inter text-[12px] text-ink-500">Documento liberado no portal</div>
                                        <div class="mt-1 font-jetbrains text-[10px] uppercase tracking-[.08em] text-ink-400">
                                            {{ \Illuminate\Support\Number::fileSize((int) $file->size_bytes) }} · {{ $file->uploaded_at?->format('d/m/Y') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                                @if (\Illuminate\Support\Facades\Gate::forUser($nimbusUser)->check('downloadFile', [$submission, $file]))
                                    <a href="{{ route('nimbus.submissions.files.download', [$submission, $file]) }}" class="submission-download-pill" title="Baixar documento de retorno">
                                        <i class="bi bi-download text-[11px]"></i>
                                        Baixar
                                    </a>
                                @endif
                            </div>
                        @empty
                            <div class="submission-empty-state">
                                <div class="mb-3">
                                    <i class="bi bi-inbox text-3xl text-ink-200"></i>
                                </div>
                                <h3 class="font-fraunces mb-2 text-lg text-navy-900">Nenhum documento de retorno</h3>
                                <p class="font-inter mx-auto max-w-sm text-sm text-ink-500">Quando a equipe liberar algum arquivo para você, ele ficará disponível nesta área.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    @media (min-width: 992px) {
        .nd-sticky-sidebar {
            position: sticky;
            top: 7rem;
            max-height: calc(100vh - 8.5rem);
            overflow-y: auto;
        }
    }

    .submission-back-button {
        display: inline-flex;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--color-ink-200);
        border-radius: 999px;
        background: #fff;
        color: var(--color-navy-700);
        text-decoration: none;
        box-shadow: var(--shadow-portal-subtle);
        transition: all 0.2s ease;
    }

    .submission-back-button:hover {
        border-color: rgba(165, 132, 50, 0.22);
        background: var(--color-gold-50);
        color: var(--color-navy-900);
    }

    .submission-meta-pill,
    .submission-count-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        border: 1px solid rgba(31, 63, 117, 0.12);
        background: rgba(242, 245, 250, 0.92);
        color: var(--color-navy-700);
        font: 500 11px/1 var(--font-jetbrains);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .submission-meta-pill-muted {
        border-color: var(--color-ink-200);
        background: #fff;
        color: var(--color-ink-500);
    }

    .submission-count-pill {
        min-width: 32px;
    }

    .submission-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 0 14px;
        border-radius: 999px;
        border: 1px solid transparent;
        font: 600 12px/1 var(--font-inter);
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .submission-status-pill.warning {
        background: #fbf1dd;
        color: #946420;
        border-color: rgba(148, 100, 32, 0.18);
    }

    .submission-status-pill.info {
        background: #f2f5fa;
        color: #16305c;
        border-color: rgba(31, 63, 117, 0.16);
    }

    .submission-status-pill.success {
        background: #e6f1ec;
        color: #1e7a56;
        border-color: rgba(30, 122, 86, 0.18);
    }

    .submission-status-pill.danger {
        background: #f7e5e8;
        color: #9b2d3e;
        border-color: rgba(155, 45, 62, 0.18);
    }

    .submission-status-pill.gray {
        background: #f5f7fb;
        color: #5a6478;
        border-color: #d4d9e2;
    }

    .submission-callout {
        display: flex;
        flex-direction: column;
        gap: 16px;
        padding: 24px 26px;
        border: 1px solid rgba(165, 132, 50, 0.18);
        border-radius: 10px;
        background: linear-gradient(135deg, rgba(251, 241, 221, 0.95), rgba(255, 255, 255, 0.98));
        box-shadow: var(--shadow-portal-subtle);
    }

    .submission-callout-icon {
        display: inline-flex;
        width: 44px;
        height: 44px;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(184, 150, 74, 0.16);
        color: var(--color-gold-700);
        font-size: 18px;
    }

    .submission-callout-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--color-navy-700);
        font: 600 13px/1 var(--font-inter);
        text-decoration: none;
    }

    .submission-callout-link:hover {
        color: var(--color-navy-900);
    }

    .submission-panel {
        overflow: hidden;
        border: 1px solid var(--color-ink-200);
        border-radius: 8px;
        background: #fff;
        box-shadow: var(--shadow-portal-subtle);
    }

    .submission-panel-highlight {
        border-color: rgba(165, 132, 50, 0.22);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(251, 247, 234, 0.9));
    }

    .submission-panel-header {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 16px;
        padding: 24px 28px;
        border-bottom: 1px solid var(--color-ink-100);
    }

    .submission-panel-body {
        padding: 24px 28px;
    }

    .submission-scroll-area {
        max-height: 420px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(123, 132, 153, 0.45) transparent;
    }

    .submission-scroll-area::-webkit-scrollbar {
        width: 8px;
    }

    .submission-scroll-area::-webkit-scrollbar-track {
        background: transparent;
    }

    .submission-scroll-area::-webkit-scrollbar-thumb {
        border-radius: 999px;
        background: rgba(123, 132, 153, 0.38);
    }

    .submission-scroll-area::-webkit-scrollbar-thumb:hover {
        background: rgba(123, 132, 153, 0.55);
    }

    .submission-data-card {
        padding: 18px 20px;
        border: 1px solid var(--color-ink-100);
        border-radius: 8px;
        background: linear-gradient(180deg, rgba(245, 247, 251, 0.55), rgba(255, 255, 255, 0.96));
    }

    .submission-data-label,
    .submission-field-label {
        margin-bottom: 8px;
        color: var(--color-ink-400);
        font: 600 11px/1.2 var(--font-inter);
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .submission-data-value {
        color: var(--color-navy-900);
        font: 600 14px/1.4 var(--font-inter);
    }

    .submission-data-link {
        color: var(--color-navy-700);
        font: 600 14px/1.4 var(--font-inter);
        text-decoration: none;
        overflow-wrap: anywhere;
    }

    .submission-data-link:hover {
        color: var(--color-navy-900);
        text-decoration: underline;
        text-decoration-color: var(--color-gold-500);
    }

    .submission-data-meta,
    .submission-field-help {
        margin-top: 6px;
        color: var(--color-ink-500);
        font: 400 12px/1.5 var(--font-inter);
    }

    .submission-section-block {
        padding-top: 24px;
        border-top: 1px solid var(--color-ink-100);
    }

    .submission-section-heading {
        margin-bottom: 14px;
        color: var(--color-navy-900);
        font: 600 13px/1.3 var(--font-inter);
    }

    .submission-flag-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .submission-flag-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        padding: 0 12px;
        border: 1px solid var(--color-ink-200);
        border-radius: 999px;
        background: #fff;
        color: var(--color-ink-400);
        font: 500 12px/1.2 var(--font-inter);
        transition: all 0.2s ease;
    }

    .submission-flag-chip.is-active {
        border-color: rgba(31, 63, 117, 0.18);
        background: var(--color-navy-50);
        color: var(--color-navy-700);
    }

    .submission-flag-chip.is-active.is-positive {
        border-color: rgba(30, 122, 86, 0.18);
        background: var(--color-emerald-50);
        color: var(--color-emerald-600);
    }

    .submission-message {
        padding: 22px 28px;
    }

    .submission-note-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 24px;
        padding: 0 10px;
        border-radius: 999px;
        background: var(--color-navy-50);
        color: var(--color-navy-700);
        font: 600 10px/1 var(--font-jetbrains);
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .submission-empty-state {
        padding: 48px 24px;
        text-align: center;
    }

    .submission-form-control,
    .submission-file-input {
        width: 100%;
        border: 1px solid var(--color-ink-200);
        border-radius: 8px;
        background: #fff;
        color: var(--color-navy-900);
        font: 400 14px/1.5 var(--font-inter);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .submission-form-control {
        min-height: 132px;
        padding: 14px 16px;
        resize: vertical;
    }

    .submission-file-input {
        padding: 11px 14px;
    }

    .submission-form-control:focus,
    .submission-file-input:focus {
        outline: none;
        border-color: var(--color-gold-500);
        box-shadow: 0 0 0 3px rgba(184, 150, 74, 0.12);
    }

    .submission-field-error {
        margin-top: 8px;
        color: var(--color-rose-600);
        font: 500 12px/1.4 var(--font-inter);
    }

    .submission-table thead th {
        background: var(--color-ink-50);
        color: var(--color-ink-500);
        font: 600 11px/1.2 var(--font-inter);
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .submission-table tbody tr {
        border-top: 1px solid var(--color-ink-100);
    }

    .submission-table tbody tr:hover {
        background: #fafbfd;
    }

    .submission-file-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 20px 24px;
    }

    .submission-file-icon {
        display: inline-flex;
        width: 38px;
        height: 38px;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 17px;
    }

    .submission-file-icon-user {
        background: rgba(155, 45, 62, 0.08);
        color: var(--color-rose-600);
    }

    .submission-file-icon-response {
        background: rgba(30, 122, 86, 0.08);
        color: var(--color-emerald-600);
    }

    .doc-btn-square {
        display: inline-flex;
        width: 34px;
        height: 34px;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--color-ink-200);
        border-radius: 5px;
        background: #fff;
        color: var(--color-ink-700);
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .doc-btn-square:hover {
        border-color: rgba(165, 132, 50, 0.26);
        background: var(--color-gold-50);
        color: var(--color-navy-900);
    }

    .submission-download-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 34px;
        padding: 0 12px;
        border: 1px solid var(--color-ink-200);
        border-radius: 999px;
        background: #fff;
        color: var(--color-navy-700);
        font: 600 12px/1 var(--font-inter);
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .submission-download-pill:hover {
        border-color: rgba(165, 132, 50, 0.24);
        background: var(--color-gold-50);
        color: var(--color-navy-900);
    }

    @media (min-width: 992px) {
        .submission-callout {
            flex-direction: row;
            align-items: center;
        }
    }

    @media (max-width: 767.98px) {
        .submission-panel-header,
        .submission-panel-body,
        .submission-message,
        .submission-file-row {
            padding-left: 20px;
            padding-right: 20px;
        }

        .submission-file-row {
            flex-direction: column;
            align-items: stretch;
        }

        .submission-download-pill,
        .doc-btn-square {
            align-self: flex-start;
        }
    }
</style>
@endpush
