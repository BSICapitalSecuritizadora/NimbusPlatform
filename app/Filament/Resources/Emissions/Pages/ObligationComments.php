<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\Obligation;
use App\Models\ObligationComment;
use App\Services\Obligations\ObligationCommentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class ObligationComments extends Page
{
    use InteractsWithRecord;
    use WithPagination;

    protected static string $resource = EmissionResource::class;

    protected string $view = 'filament.resources.emissions.pages.obligation-comments';

    protected static ?string $title = 'Comentários internos';

    public string $newComment = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

    public int $obligationId;

    public function mount(int|string $record, int|string $obligation): void
    {
        abort_unless(static::canAccess(), 403);

        $this->record = $this->resolveRecord($record);
        $this->obligationId = (int) $obligation;

        $this->obligation();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return (auth()->user()?->can(AccessPermission::EmissionsView->value) ?? false)
            && (auth()->user()?->can(AccessPermission::ObligationsView->value) ?? false)
            && (auth()->user()?->can(AccessPermission::ObligationsViewComments->value) ?? false);
    }

    public function getTitle(): string
    {
        return 'Comentários internos';
    }

    public function obligation(): Obligation
    {
        return Obligation::query()
            ->with('responsibleUser')
            ->where('emission_id', $this->getRecord()->id)
            ->findOrFail($this->obligationId);
    }

    public function getCommentsProperty(): LengthAwarePaginator
    {
        return $this->obligation()
            ->comments()
            ->getQuery()
            ->with(['author', 'editor'])
            ->paginate(10);
    }

    public function addComment(): void
    {
        $this->newComment = trim($this->newComment);

        $this->validate([
            'newComment' => ['required', 'string', 'max:'.ObligationCommentService::BODY_MAX_LENGTH],
        ], [
            'newComment.required' => 'Informe o conteúdo do comentário.',
            'newComment.max' => 'O comentário deve ter no máximo '.ObligationCommentService::BODY_MAX_LENGTH.' caracteres.',
        ]);

        $this->commentService()->create($this->obligation(), auth()->user(), $this->newComment);

        $this->reset('newComment');
        $this->resetPage();

        Notification::make()
            ->success()
            ->title('Comentário adicionado com sucesso.')
            ->send();
    }

    public function beginEditing(int $commentId): void
    {
        $comment = $this->findComment($commentId);

        abort_unless($this->commentService()->canUserUpdate(auth()->user(), $comment), 403);

        $this->editingCommentId = $comment->id;
        $this->editingCommentBody = (string) $comment->body;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editingCommentId = null;
        $this->editingCommentBody = '';
        $this->resetValidation();
    }

    public function saveEditedComment(): void
    {
        if ($this->editingCommentId === null) {
            return;
        }

        $this->editingCommentBody = trim($this->editingCommentBody);

        $this->validate([
            'editingCommentBody' => ['required', 'string', 'max:'.ObligationCommentService::BODY_MAX_LENGTH],
        ], [
            'editingCommentBody.required' => 'Informe o conteúdo do comentário.',
            'editingCommentBody.max' => 'O comentário deve ter no máximo '.ObligationCommentService::BODY_MAX_LENGTH.' caracteres.',
        ]);

        $comment = $this->findComment($this->editingCommentId);

        $this->commentService()->update($comment, auth()->user(), $this->editingCommentBody);

        $this->cancelEditing();

        Notification::make()
            ->success()
            ->title('Comentário atualizado com sucesso.')
            ->send();
    }

    public function removeComment(int $commentId): void
    {
        $comment = $this->findComment($commentId);

        $this->commentService()->delete($comment, auth()->user());

        if ($this->editingCommentId === $commentId) {
            $this->cancelEditing();
        }

        Notification::make()
            ->success()
            ->title('Comentário removido com sucesso.')
            ->send();
    }

    public function canCreateComment(): bool
    {
        return $this->commentService()->canUserCreate(auth()->user());
    }

    public function canEditComment(ObligationComment $comment): bool
    {
        return $this->commentService()->canUserUpdate(auth()->user(), $comment);
    }

    public function canDeleteComment(ObligationComment $comment): bool
    {
        return $this->commentService()->canUserDelete(auth()->user(), $comment);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEmission')
                ->label('Voltar para a emissão')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => EmissionResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    protected function findComment(int $commentId): ObligationComment
    {
        return $this->obligation()
            ->comments()
            ->getQuery()
            ->whereKey($commentId)
            ->firstOrFail();
    }

    protected function commentService(): ObligationCommentService
    {
        return app(ObligationCommentService::class);
    }
}
