<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Convite de acesso — BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#ece9e8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#091b23;border-radius:16px 16px 0 0;padding:28px 32px;color:#e6e4e4;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">Convite de acesso ao portal</div>
        </div>
        <div style="background:#f7f5f4;border:1px solid #cdd1d2;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Prezado(a),</p>
            <p>Você foi convidado(a) para acessar o portal BSI Capital. Clique no botão abaixo para concluir seu cadastro e criar sua senha de acesso.</p>

            <div style="text-align:center;margin:32px 0;">
                <a href="{{ route('register', ['token' => $invitation->token]) }}"
                   style="display:inline-block;background:#091b23;color:#e6e4e4;text-decoration:none;font-weight:700;font-size:15px;padding:14px 32px;border-radius:8px;letter-spacing:.02em;">
                    Concluir cadastro
                </a>
            </div>

            <div style="background:#f2efee;border:1px solid #d7e0e3;border-radius:12px;padding:20px;margin:24px 0;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:8px;font-weight:600;">Informações do convite</div>
                <div style="font-size:14px;color:#374151;">
                    <strong>E-mail cadastrado:</strong> {{ $invitation->email }}<br>
                    <strong>Válido até:</strong> {{ $invitation->expires_at->format('d/m/Y \à\s H:i') }}
                </div>
            </div>

            <p style="font-size:14px;color:#6b7280;">Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:</p>
            <p style="font-size:13px;color:#091b23;word-break:break-all;">
                {{ route('register', ['token' => $invitation->token]) }}
            </p>

            <p style="margin:24px 0 0;color:#6b7280;font-size:14px;">Caso não reconheça este convite, desconsidere este e-mail. Em caso de dúvidas, entre em contato diretamente com a equipe BSI Capital.</p>
        </div>
        <div style="text-align:center;padding:20px 0;font-size:12px;color:#9ca3af;">
            BSI Capital Securitizadora S/A — Comunicação Institucional
        </div>
    </div>
</body>
</html>
