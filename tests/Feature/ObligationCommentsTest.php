<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ObligationComments;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationComment;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use App\Services\Obligations\ObligationCommentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeCommentUserWithPermissions(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(array_merge([
        AccessPermission::EmissionsView->value,
        AccessPermission::EmissionsUpdate->value,
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewComments->value,
    ], $permissions));

    return $user;
}

function commentsPageTest(Emission $emission, Obligation $obligation): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ObligationComments::class, [
        'record' => $emission->getRouteKey(),
        'obligation' => $obligation->getRouteKey(),
    ]);
}

function commentHistoryEntry(Obligation $obligation, string $eventType): ?ObligationHistoryEntry
{
    return $obligation->historyEntries()
        ->where('event_type', $eventType)
        ->latest('occurred_at')
        ->latest('id')
        ->first();
}

it('allows a user with the view comments permission to visualize comments', function () {
    $user = makeCommentUserWithPermissions([]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $comment = ObligationComment::factory()->for($obligation)->for($emission)->create([
        'user_id' => $user->id,
        'body' => 'Primeiro comentário operacional.',
    ]);

    $this->get(EmissionResource::getUrl('obligation-comments', [
        'record' => $emission,
        'obligation' => $obligation,
    ]))
        ->assertSuccessful()
        ->assertSee('Comentários internos')
        ->assertSee('Primeiro comentário operacional.')
        ->assertSee($comment->author?->name ?? $user->name);
});

it('blocks the comments page for users without the view comments permission', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([
        AccessPermission::EmissionsView->value,
        AccessPermission::ObligationsView->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();

    expect(ObligationComments::canAccess())->toBeFalse();

    $this->get(EmissionResource::getUrl('obligation-comments', [
        'record' => $emission,
        'obligation' => $obligation,
    ]))->assertForbidden();
});

it('creates a comment and links it to the obligation emission and author', function () {
    $user = makeCommentUserWithPermissions([
        AccessPermission::ObligationsCreateComment->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create([
        'status' => 'a_vencer',
    ]);

    $comment = app(ObligationCommentService::class)->create(
        $obligation,
        $user,
        'Aguardando retorno da área jurídica.',
    );

    $history = commentHistoryEntry($obligation, ObligationHistoryEntry::EVENT_COMMENT_ADDED);

    expect($comment)->not->toBeNull()
        ->and($comment->obligation_id)->toBe($obligation->id)
        ->and($comment->emission_id)->toBe($emission->id)
        ->and($comment->user_id)->toBe($user->id)
        ->and($comment->body)->toBe('Aguardando retorno da área jurídica.')
        ->and($comment->is_internal)->toBeTrue()
        ->and($obligation->fresh()->status)->toBe('a_vencer')
        ->and($history)->not->toBeNull()
        ->and($history->description)->toBe('Comentário interno adicionado.')
        ->and($history->description)->not->toContain('Aguardando retorno da área jurídica.');
});

it('requires comment content when creating a comment', function () {
    $user = makeCommentUserWithPermissions([
        AccessPermission::ObligationsCreateComment->value,
    ]);
    $obligation = Obligation::factory()->create();

    expect(fn () => app(ObligationCommentService::class)->create($obligation, $user, '   '))
        ->toThrow(ValidationException::class);
});

it('blocks comment creation for users without the specific permission', function () {
    $user = makeCommentUserWithPermissions([]);
    $obligation = Obligation::factory()->create();

    expect(fn () => app(ObligationCommentService::class)->create($obligation, $user, 'Tentativa sem permissão.'))
        ->toThrow(AuthorizationException::class);
});

it('updates a comment and tracks edited metadata', function () {
    $user = makeCommentUserWithPermissions([
        AccessPermission::ObligationsUpdateComment->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $comment = ObligationComment::factory()->for($obligation)->for($emission)->create([
        'user_id' => $user->id,
        'body' => 'Comentário original.',
    ]);

    app(ObligationCommentService::class)->update($comment, $user, 'Comentário revisado pela operação.');

    $comment->refresh();
    $history = commentHistoryEntry($obligation, ObligationHistoryEntry::EVENT_COMMENT_UPDATED);

    expect($comment->body)->toBe('Comentário revisado pela operação.')
        ->and($comment->edited_at)->not->toBeNull()
        ->and($comment->edited_by)->toBe($user->id)
        ->and($history)->not->toBeNull()
        ->and($history->description)->toBe('Comentário interno editado.')
        ->and($history->description)->not->toContain('Comentário revisado pela operação.');
});

it('blocks comment updates for users without the specific permission', function () {
    $user = makeCommentUserWithPermissions([]);
    $comment = ObligationComment::factory()->create();

    expect(fn () => app(ObligationCommentService::class)->update($comment, $user, 'Atualização indevida.'))
        ->toThrow(AuthorizationException::class);
});

it('deletes a comment with soft delete and records a summary in the history', function () {
    $user = makeCommentUserWithPermissions([
        AccessPermission::ObligationsDeleteComment->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $comment = ObligationComment::factory()->for($obligation)->for($emission)->create([
        'user_id' => $user->id,
        'body' => 'Comentário a ser removido.',
    ]);

    app(ObligationCommentService::class)->delete($comment, $user);

    $history = commentHistoryEntry($obligation, ObligationHistoryEntry::EVENT_COMMENT_REMOVED);

    $this->assertSoftDeleted('obligation_comments', [
        'id' => $comment->id,
    ]);

    expect($obligation->fresh()->status)->toBe('em_dia')
        ->and($history)->not->toBeNull()
        ->and($history->description)->toBe('Comentário interno removido.')
        ->and($history->description)->not->toContain('Comentário a ser removido.');
});

it('blocks comment deletion for users without the specific permission', function () {
    $user = makeCommentUserWithPermissions([]);
    $comment = ObligationComment::factory()->create();

    expect(fn () => app(ObligationCommentService::class)->delete($comment, $user))
        ->toThrow(AuthorizationException::class);
});

it('shows the comments action only to users with permission to view comments', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();

    $this->actingAs(makeCommentUserWithPermissions([]));

    Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionVisible('comments', $obligation);

    $userWithoutComments = User::factory()->withTwoFactor()->create();
    $userWithoutComments->givePermissionTo([
        AccessPermission::EmissionsView->value,
        AccessPermission::EmissionsUpdate->value,
        AccessPermission::ObligationsView->value,
    ]);
    $this->actingAs($userWithoutComments);

    Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionHidden('comments', $obligation);
});

it('keeps comment access available to super admins', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();

    app(ObligationCommentService::class)->create($obligation, $user, 'Comentário do super admin.');

    $this->get(EmissionResource::getUrl('obligation-comments', [
        'record' => $emission,
        'obligation' => $obligation,
    ]))
        ->assertSuccessful()
        ->assertSee('Comentário do super admin.');
});

it('renders comments safely without interpreting html', function () {
    $user = makeCommentUserWithPermissions([]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    ObligationComment::factory()->for($obligation)->for($emission)->create([
        'body' => '<script>alert("x")</script> Comentário seguro',
    ]);

    $response = $this->get(EmissionResource::getUrl('obligation-comments', [
        'record' => $emission,
        'obligation' => $obligation,
    ]));

    $response->assertSuccessful()
        ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Comentário seguro', false)
        ->assertDontSee('<script>alert("x")</script>', false);
});
