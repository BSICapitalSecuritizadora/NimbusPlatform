<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Nova submissão</title></head>
<body style="font-family:Helvetica,Arial,sans-serif; background:#ece9e8; margin:0; padding:40px;">
<div style="max-width:600px; margin:0 auto; background:#fff; border-radius:12px; padding:32px;">
<p style="font-size:16px; color:#091b23;">Nova submissão recebida — <strong>{{ $submission->reference_code }}</strong></p>
<p style="font-size:14px; color:#4b5563;">Uma nova solicitação de cadastro foi recebida no portal Gestão Documental Externa.</p>
<p style="font-size:13px; color:#6b7280;">Empresa: {{ $submission->company_name ?? '—' }} | CNPJ: {{ $submission->company_cnpj ?? '—' }}</p>
<p style="font-size:13px; color:#6b7280;">Acesse o painel administrativo para análise.</p>
</div>
</body>
</html>
