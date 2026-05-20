<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Models\JobApplication;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListJobApplications extends ListRecords
{
    protected static string $resource = JobApplicationResource::class;

    protected static ?string $title = 'Candidaturas';

    public function getTabs(): array
    {
        return [
            'todas' => \Filament\Schemas\Components\Tabs\Tab::make('Todas as Candidaturas'),
            'novas' => \Filament\Schemas\Components\Tabs\Tab::make('Novas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_NEW)),
            'triagem' => \Filament\Schemas\Components\Tabs\Tab::make('Em Triagem')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_SCREENING)),
            'entrevista' => \Filament\Schemas\Components\Tabs\Tab::make('Em Entrevista')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_INTERVIEW)),
            'finalistas' => \Filament\Schemas\Components\Tabs\Tab::make('Finalistas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_FINALIST)),
            'encerradas' => \Filament\Schemas\Components\Tabs\Tab::make('Encerradas / Histórico')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    JobApplication::STATUS_HIRED,
                    JobApplication::STATUS_REJECTED,
                ])),
        ];
    }
}
