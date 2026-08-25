<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Submissão concluída</title></head>
<body style="font-family:Helvetica,Arial,sans-serif; background:#ece9e8; margin:0; padding:40px;">
<div style="max-width:600px; margin:0 auto; background:#fff; border-radius:12px; padding:32px;">
<p style="font-size:16px; color:#091b23;">Olá, {{ explode(' ', $submission->portalUser->full_name ?? 'Parceiro')[0] }}!</p>
<p style="font-size:14px; color:#4b5563;">Sua solicitação <strong>{{ $submission->reference_code }}</strong> foi <strong>concluída com sucesso</strong>.</p>
<p style="font-size:13px; color:#6b7280;">Agradecemos o envio da documentação.</p>
</div>
</body>
</html>
