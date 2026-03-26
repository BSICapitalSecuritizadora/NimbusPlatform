<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Continue sua proposta</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#001233;border-radius:16px 16px 0 0;padding:28px 32px;color:#fff;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;">Continue o preenchimento da sua proposta</div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Olá, {{ $proposal->contact->name }}.</p>
            <p>Recebemos a etapa inicial da proposta da empresa <strong>{{ $proposal->company->name }}</strong>.</p>
            <p>Para continuar o preenchimento das informações do empreendimento, use o link seguro abaixo e informe o CNPJ da empresa junto com o código de acesso.</p>

            <div style="margin:28px 0;">
                <a href="{{ $continuationUrl }}" style="display:inline-block;background:#001233;color:#fff;text-decoration:none;padding:14px 22px;border-radius:10px;font-weight:700;">
                    Continuar proposta
                </a>
            </div>

            <div style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:6px;">Código de acesso</div>
                <div style="font-size:32px;font-weight:700;letter-spacing:0.18em;color:#001233;">{{ $code }}</div>
            </div>

            <p style="margin:24px 0 0;">Este acesso expira em <strong>{{ $access->expires_at->format('d/m/Y H:i') }}</strong>.</p>
        </div>
    </div>
</body>
</html>
