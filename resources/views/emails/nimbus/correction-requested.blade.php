<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Correção necessária</title></head>
<body style="font-family:Helvetica,Arial,sans-serif; background:#ece9e8; margin:0; padding:40px;">
<div style="max-width:600px; margin:0 auto; background:#fff; border-radius:12px; padding:32px;">
<p style="font-size:16px; color:#091b23;">Olá, {{ explode(' ', $submission->portalUser->full_name ?? 'Parceiro')[0] }}!</p>
<p style="font-size:14px; color:#4b5563;">Sua solicitação <strong>{{ $submission->reference_code }}</strong> requer correção.</p>
<p style="font-size:14px; color:#4b5563;">Acesse o portal seguro para verificar as instruções detalhadas e enviar a documentação corrigida.</p>
<p style="margin-top:24px;"><a href="{{ route('nimbus.submissions.index') }}" style="background:#091b23; color:#fff; padding:12px 24px; border-radius:8px; text-decoration:none;">Acessar Portal</a></p>
</div>
</body>
</html>
