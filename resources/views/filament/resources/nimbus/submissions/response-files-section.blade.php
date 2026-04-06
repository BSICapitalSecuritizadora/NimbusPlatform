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
                {{ $responseFiles->count() }} documento(s) de retorno registrado(s).
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
                                        Disponível no Portal
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-500/15 px-2.5 py-1 text-[11px] font-medium text-gray-300 ring-1 ring-inset ring-gray-400/30">
                                        Restrito (Interno)
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
                            class="fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-2 rounded-xl border border-primary-400/40 px-4 py-2 text-sm font-medium text-primary-200 transition hover:border-primary-300 hover:bg-primary-500/10 hover:text-primary-100"
                        >
                            <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                            <span>Download</span>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="rounded-2xl border border-dashed border-gray-700 bg-gray-900/30 p-4 md:p-5">
        <div class="mb-4 flex items-center gap-2 text-sm font-semibold text-white">
            <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="h-5 w-5 text-gray-400" />
            <span>Anexar Resposta</span>
        </div>

        @if ($responseFileErrors->isNotEmpty())
            <div class="mb-4 rounded-xl border border-danger-500/30 bg-danger-500/10 p-3 text-sm text-danger-200">
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
                <input
                    type="file"
                    name="response_files[]"
                    multiple
                    class="block w-full rounded-xl border border-gray-700 bg-gray-950 px-4 py-3 text-sm text-gray-200 file:mr-4 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-white/15"
                >
                <p class="text-sm text-gray-400">
                    Formatos aceitos: PDF, DOCX, XLSX, ZIP e imagens.
                </p>
            </div>

            <div>
                <button
                    type="submit"
                    class="fi-btn fi-color-warning fi-btn-size-sm inline-flex items-center justify-center gap-2 rounded-xl bg-warning-500 px-4 py-2 text-sm font-semibold text-warning-950 transition hover:bg-warning-400"
                >
                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                    <span>Enviar Arquivos</span>
                </button>
            </div>
        </form>
    </div>
</div>
