<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Continuação de proposta — BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#ece9e8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#091b23;border-radius:16px 16px 0 0;padding:28px 32px;color:#e6e4e4;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">Continuação do preenchimento — Proposta Comercial</div>
        </div>
        <div style="background:#f7f5f4;border:1px solid #cdd1d2;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Prezado(a) {{ $proposal->contact->name }},</p>
            <p>Recebemos com sucesso a etapa inicial da proposta da empresa <strong>{{ $proposal->company->name }}</strong>.</p>
            <p>Para prosseguir com o preenchimento das informações do empreendimento, utilize o link seguro abaixo. Ao acessar, informe o CNPJ da empresa e o código de acesso disponibilizado neste e-mail.</p>

            <div style="margin:28px 0;">
                <a href="{{ $continuationUrl }}" style="display:inline-block;background:#091b23;color:#e6e4e4;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:700;font-size:15px;">
                    Acessar formulário de continuação
                </a>
            </div>

            <div style="background:#f2efee;border:1px solid #d7e0e3;border-radius:12px;padding:20px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:8px;font-weight:600;">Código de acesso</div>
                <div style="font-size:32px;font-weight:700;letter-spacing:0.2em;color:#091b23;">{{ $code }}</div>
            </div>

            <p style="margin:24px 0 0;color:#6b7280;font-size:14px;">Este acesso é válido até <strong style="color:#1f2937;">{{ $access->expires_at->format('d/m/Y \à\s H:i') }}</strong>. Não compartilhe este código com terceiros.</p>
        </div>
        <div style="text-align:center;padding:20px 0;font-size:12px;color:#9ca3af;">
            BSI Capital Securitizadora S/A — Comunicação Institucional
        </div>
    </div>
</body>
</html>
