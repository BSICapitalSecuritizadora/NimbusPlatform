<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Mail\UserInvitationMail;
use App\Models\Invitation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['token'] = Str::random(64);
        $data['expires_at'] = now()->addDays(7);
        $data['invited_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Invitation $invitation */
        $invitation = $this->record;

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation));

        Notification::make()
            ->title('Convite enviado com sucesso para '.$invitation->email)
            ->success()
            ->send();
    }
}
