<!DOCTYPE html>
<html lang="pt-br"><head><meta charset="UTF-8"><title>Relatório Analítico - {{ $project->name }}</title>@include('pdf.partials.proposal-report-styles')</head>
<body><div class="header"><h1>BSI CAPITAL</h1><p>Relatório Analítico do Empreendimento</p></div><div class="gold-bar"></div>
<div class="content"><div class="document-meta">Proposta #{{ $project->proposal_id }} · Gerado em {{ now()->format('d/m/Y H:i') }}</div>@include('pdf.partials.project-report-sections', ['showIndicators' => true])</div>
<div class="footer">BSI CAPITAL SECURITIZADORA S/A</div></body></html>
