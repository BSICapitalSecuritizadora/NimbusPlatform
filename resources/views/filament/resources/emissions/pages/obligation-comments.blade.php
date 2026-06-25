<x-filament-panels::page>
    @php
        $obligation = $this->obligation();
        $comments = $this->comments;
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Comentários internos</x-slot>
            <x-slot name="description">
                Canal operacional separado do histórico técnico da obrigação.
            </x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Obrigação</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->title }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->status_label }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Vencimento</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->due_date?->format('d/m/Y') ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Responsável</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->responsibleUser?->name ?? '—' }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Novo comentário</x-slot>

            @if ($this->canCreateComment())
                <div class="space-y-3">
                    <div>
                        <label for="newComment" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Adicionar comentário
                        </label>
                        <textarea
                            id="newComment"
                            wire:model="newComment"
                            rows="4"
                            maxlength="2000"
                            class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            placeholder="Registre o contexto operacional desta obrigação."
                        ></textarea>
                        @error('newComment')
                            <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <x-filament::button wire:click="addComment" icon="heroicon-o-paper-airplane">
                            Adicionar comentário
                        </x-filament::button>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Você não tem permissão para comentar nesta obrigação.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Comentários registrados</x-slot>

            <div class="space-y-4">
                @forelse ($comments as $comment)
                    <article wire:key="obligation-comment-{{ $comment->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    Comentado por {{ $comment->author?->name ?? 'Usuário removido' }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $comment->created_at?->format('d/m/Y H:i') }}
                                    @if ($comment->edited_at)
                                        · Editado em {{ $comment->edited_at->format('d/m/Y H:i') }}
                                        @if ($comment->editor?->name)
                                            por {{ $comment->editor->name }}
                                        @endif
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                @if ($this->canEditComment($comment))
                                    <x-filament::button size="xs" color="gray" wire:click="beginEditing({{ $comment->id }})">
                                        Editar comentário
                                    </x-filament::button>
                                @endif

                                @if ($this->canDeleteComment($comment))
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        x-on:click="if (confirm('Remover este comentário interno?')) { $wire.removeComment({{ $comment->id }}) }"
                                    >
                                        Remover comentário
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>

                        @if ($this->editingCommentId === $comment->id)
                            <div class="mt-4 space-y-3">
                                <div>
                                    <textarea
                                        wire:model="editingCommentBody"
                                        rows="4"
                                        maxlength="2000"
                                        class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                        placeholder="Atualize o comentário interno."
                                    ></textarea>
                                    @error('editingCommentBody')
                                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex justify-end gap-2">
                                    <x-filament::button size="sm" color="gray" wire:click="cancelEditing">
                                        Cancelar
                                    </x-filament::button>
                                    <x-filament::button size="sm" wire:click="saveEditedComment">
                                        Salvar comentário
                                    </x-filament::button>
                                </div>
                            </div>
                        @else
                            <div class="mt-4 text-sm leading-6 text-gray-700 dark:text-gray-200">
                                {!! nl2br(e($comment->body)) !!}
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nenhum comentário registrado.
                    </p>
                @endforelse
            </div>

            @if ($comments->hasPages())
                <div class="pt-4">
                    {{ $comments->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
