<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Models\JobApplication;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListJobApplications extends ListRecords
{
    protected static string $resource = JobApplicationResource::class;

    public function getTabs(): array
    {
        return [
            'todas' => \Filament\Schemas\Components\Tabs\Tab::make('Todas'),
            'novas' => \Filament\Schemas\Components\Tabs\Tab::make('Novas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_NEW)),
            'triagem' => \Filament\Schemas\Components\Tabs\Tab::make('Triagem')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_SCREENING)),
            'entrevista' => \Filament\Schemas\Components\Tabs\Tab::make('Entrevista')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_INTERVIEW)),
            'finalistas' => \Filament\Schemas\Components\Tabs\Tab::make('Finalistas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', JobApplication::STATUS_FINALIST)),
            'encerradas' => \Filament\Schemas\Components\Tabs\Tab::make('Encerradas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    JobApplication::STATUS_HIRED,
                    JobApplication::STATUS_REJECTED,
                ])),
        ];
    }
}
