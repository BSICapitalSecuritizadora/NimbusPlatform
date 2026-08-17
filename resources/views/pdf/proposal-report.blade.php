<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Relatório Geral da Solicitação #{{ $proposal->id }}</title>@include('pdf.partials.proposal-report-styles')</head>
<body>
<div class="header"><h1>BSI CAPITAL</h1><p>Relatório Geral da Solicitação</p></div><div class="gold-bar"></div>
<div class="content">
    <div class="document-meta">Proposta #{{ $proposal->id }} · Gerado em {{ now()->format('d/m/Y H:i') }}</div>
    <h2 class="section-title">Empresa e solicitação</h2>
    <table class="info-table">
        <tr><th>Empresa</th><td>{{ $proposal->company->name }}</td><th>CNPJ</th><td>{{ $proposal->company->cnpj }}</td></tr>
        <tr><th>Inscrição Estadual</th><td>{{ $proposal->company->ie ?: '—' }}</td><th>Setores</th><td>{{ $proposal->company->sectors->pluck('name')->join(', ') ?: '—' }}</td></tr>
        <tr><th>Site</th><td>{{ $proposal->company->site ?: '—' }}</td><th>Responsável interno</th><td>{{ $proposal->representative?->name ?: '—' }}</td></tr>
        <tr><th>Endereço</th><td colspan="3">{{ $proposal->company->full_address }}</td></tr>
        <tr><th>Status</th><td>{{ \App\Enums\ProposalStatus::labelFor($proposal->status) }}</td><th>Recebida em</th><td>{{ $proposal->created_at?->format('d/m/Y H:i') }}</td></tr>
        <tr><th>Complementada em</th><td colspan="3">{{ $proposal->completed_at?->format('d/m/Y H:i') ?: '—' }}</td></tr>
        <tr><th>Observações</th><td colspan="3">{{ $proposal->observations ?: '—' }}</td></tr>
    </table>
    <h2 class="section-title">Contato responsável</h2>
    <table class="info-table">
        <tr><th>Nome</th><td>{{ $proposal->contact->name }}</td><th>Cargo</th><td>{{ $proposal->contact->cargo ?: '—' }}</td></tr>
        <tr><th>E-mail</th><td>{{ $proposal->contact->email }}</td><th>Celular</th><td>{{ $proposal->contact->phone_personal ?: '—' }}</td></tr>
        <tr><th>Telefone da empresa</th><td colspan="3">{{ $proposal->contact->phone_company ?: '—' }}</td></tr>
        <tr><th>Possui WhatsApp</th><td>{{ $proposal->contact->whatsapp_availability_label }}</td><th>Consentimento WhatsApp</th><td>{{ $proposal->contact->whatsapp_consent_label }}</td></tr>
    </table>
    @forelse ($proposal->projects as $project)
        @php($analysis = $projectAnalyses[$project->id])
        <div class="project-break {{ $loop->first ? 'first-project' : '' }}"><div class="project-number">Empreendimento {{ $loop->iteration }} de {{ $loop->count }}</div>@include('pdf.partials.project-report-sections', ['showIndicators' => true])</div>
    @empty
        <div class="empty-state">Nenhum empreendimento foi cadastrado nesta solicitação.</div>
    @endforelse
</div>
<div class="footer">BSI CAPITAL SECURITIZADORA S/A</div>
</body></html>
