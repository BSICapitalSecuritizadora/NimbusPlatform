@php
    $responseFiles = $record?->responseFiles ?? collect();
    $responseFiles = $responseFiles->sortByDesc(fn ($file) => $file->uploaded_at ?? $file->created_at)->values();
    $responseFileErrors = collect($errors->get('response_files'))
        ->merge(collect($errors->get('response_files.*'))->flatten())
        ->filter()
        ->values();
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between text-sm text-gray-400">
        <span>
            @if ($responseFiles->isNotEmpty())
                {{ $responseFiles->count() }} {{ $responseFiles->count() === 1 ? 'documento de retorno registrado' : 'documentos de retorno registrados' }}.
            @else
                Nenhum documento de retorno registrado.
            @endif
        </span>
    </div>

    @if ($responseFiles->isNotEmpty())
        <div class="space-y-3">
            @foreach ($responseFiles as $file)
                <div class="flex flex-col gap-4 rounded-2xl border border-gray-700 bg-gray-900/40 p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full border border-amber-400/30 bg-amber-400/10 text-amber-300">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                        </div>

                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-white">{{ $file->original_name }}</span>

                                @if ($file->visible_to_user)
                                    <span class="inline-flex items-center rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-400/30">
                                        Disponível no portal
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-500/15 px-2.5 py-1 text-[11px] font-medium text-gray-300 ring-1 ring-inset ring-gray-400/30">
                                        Restrito (interno)
                                    </span>
                                @endif
                            </div>

                            <div class="text-sm text-gray-400">
                                {{ \Illuminate\Support\Number::fileSize((int) $file->size_bytes) }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end">
                        <a
                            href="{{ route('admin.nimbus.submissions.files.download', $file) }}"
                            class="fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-2 rounded-xl border border-gray-600 px-4 py-2 text-sm font-medium text-gray-200 transition hover:border-gray-500 hover:bg-white/10 hover:text-white"
                        >
                            <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                            <span>Baixar</span>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="rounded-2xl border border-dashed border-gray-700 bg-gray-900/30 p-4 md:p-5">
        <div class="mb-4 flex items-center gap-2 text-sm font-semibold text-white">
            <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="h-5 w-5 text-gray-400" />
            <span>Anexar resposta</span>
        </div>

        @if ($responseFileErrors->isNotEmpty())
            <div id="response-files-errors" class="mb-4 rounded-xl border border-danger-500/30 bg-danger-500/10 p-3 text-sm text-danger-200" role="alert" aria-live="assertive" aria-atomic="true">
                <ul class="space-y-1">
                    @foreach ($responseFileErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('admin.nimbus.submissions.response-files.store', $record) }}"
            enctype="multipart/form-data"
            class="space-y-4"
        >
            @csrf
            <input type="hidden" name="visible_to_user" value="1">

            <div class="space-y-2">
                <label for="response-files" class="block text-sm font-semibold text-white">Arquivos de resposta</label>
                <input
                    id="response-files"
                    type="file"
                    name="response_files[]"
                    multiple
                    aria-describedby="response-files-help{{ $responseFileErrors->isNotEmpty() ? ' response-files-errors' : '' }}"
                    @if ($responseFileErrors->isNotEmpty()) aria-invalid="true" @endif
                    class="block w-full rounded-xl border border-gray-700 bg-gray-950 px-4 py-3 text-sm text-gray-200 file:mr-4 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-white/15"
                >
                <p id="response-files-help" class="text-sm text-gray-400">
                    Formatos aceitos: PDF, DOCX, XLSX, ZIP e imagens. Tamanho máximo por arquivo: 100 MB.
                </p>
            </div>

            <div>
                <button
                    type="submit"
                    class="fi-btn fi-color-primary fi-btn-size-sm inline-flex items-center justify-center gap-2 rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-[#091b23] transition hover:bg-primary-400"
                >
                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                    <span>Enviar arquivos</span>
                </button>
            </div>
        </form>
    </div>
</div>
