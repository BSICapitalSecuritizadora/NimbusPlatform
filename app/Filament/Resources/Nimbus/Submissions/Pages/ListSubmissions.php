<?php

namespace App\Filament\Resources\Nimbus\Submissions\Pages;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    protected static ?string $title = 'Envios e Solicitações';

    protected static ?string $breadcrumb = 'Listar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-submissions-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe os envios recebidos e o andamento das solicitações.';
    }
}
