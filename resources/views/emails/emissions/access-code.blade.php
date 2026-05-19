<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Código de acesso à operação — BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#ece9e8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    @php
        $firstName = explode(' ', trim((string) $access->requester_name))[0] ?: 'Parceiro';
    @endphp

    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#091b23;border-radius:16px 16px 0 0;padding:28px 32px;color:#e6e4e4;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c8a874;">Gestão Documental Externa</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">Validação de acesso — Operações públicas</div>
        </div>

        <div style="background:#f7f5f4;border:1px solid #cdd1d2;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Olá, {{ $firstName }}.</p>
            <p>Recebemos sua solicitação para consultar a operação <strong>{{ $emission->name }}</strong>.</p>
            <p>Para liberar o acesso aos dados completos, utilize o link seguro abaixo e informe o código enviado neste e-mail.</p>

            <div style="margin:24px 0 0;background:#f4eee6;border:1px solid #d7c09c;border-radius:12px;padding:18px 20px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#7b541e;margin-bottom:8px;font-weight:700;">Fluxo de validação</div>
                <div style="font-size:14px;line-height:1.7;color:#4b5563;">
                    A validação foi preparada para a consulta controlada de operações públicas, preservando rastreabilidade e segurança no acesso.
                </div>
            </div>

            <div style="margin:28px 0;">
                <a href="{{ $accessUrl }}" style="display:inline-block;background:#091b23;color:#e6e4e4;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:700;font-size:15px;">
                    Validar acesso à operação
                </a>
            </div>

            <div style="background:#f2efee;border:1px solid #d7e0e3;border-radius:12px;padding:20px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:8px;font-weight:600;">Código de acesso</div>
                <div style="font-size:32px;font-weight:700;letter-spacing:0.2em;color:#091b23;">{{ $code }}</div>
            </div>

            <div style="margin-top:24px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:10px;font-weight:600;">Resumo da operação</div>
                <div style="font-size:15px;font-weight:700;color:#091b23;">{{ $emission->name }}</div>
                <div style="margin-top:8px;font-size:14px;color:#4b5563;line-height:1.7;">
                    Código IF: {{ $emission->if_code ?? '—' }}<br>
                    Emissor: {{ $emission->issuer ?? '—' }}<br>
                    Vencimento: {{ $emission->maturity_date?->format('d/m/Y') ?? '—' }}
                </div>
            </div>

            <p style="margin:24px 0 0;color:#6b7280;font-size:14px;">
                Este acesso é válido até <strong style="color:#1f2937;">{{ $access->expires_at->format('d/m/Y \à\s H:i') }}</strong>.
                Não compartilhe este código com terceiros.
            </p>

            <p style="margin:24px 0 0;color:#9ca3af;font-size:12px;line-height:1.6;">
                Se o botão não funcionar, copie e cole este link no navegador:<br>
                <a href="{{ $accessUrl }}" style="color:#2563eb;text-decoration:underline;word-break:break-all;">{{ $accessUrl }}</a>
            </p>
        </div>

        <div style="text-align:center;padding:20px 0;font-size:12px;color:#9ca3af;">
            BSI Capital Securitizadora S/A — Comunicação Institucional
        </div>
    </div>
</body>
</html>
