<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $headline }} - BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#ece9e8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#091b23;border-radius:16px 16px 0 0;padding:28px 32px;color:#e6e4e4;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">{{ $headline }}</div>
        </div>
        <div style="background:#f7f5f4;border:1px solid #cdd1d2;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Prezados(as),</p>

            @if ($notificationType === \App\Models\ObligationNotification::TYPE_OVERDUE)
                <p>Identificamos uma obrigação <strong>vencida</strong> vinculada à emissão
                    <strong>{{ $emission?->name ?? 'Não informada' }}</strong> que ainda não consta como concluída.
                    Solicitamos atenção e providências.</p>
            @elseif ($notificationType === \App\Models\ObligationNotification::TYPE_DUE_TODAY)
                <p>A obrigação abaixo, vinculada à emissão
                    <strong>{{ $emission?->name ?? 'Não informada' }}</strong>, <strong>vence hoje</strong>.
                    Solicitamos atenção e providências.</p>
            @else
                <p>A obrigação abaixo, vinculada à emissão
                    <strong>{{ $emission?->name ?? 'Não informada' }}</strong>, está <strong>próxima do vencimento</strong>.
                    Solicitamos atenção e providências.</p>
            @endif

            <div style="background:#f2efee;border:1px solid #d7e0e3;border-radius:12px;padding:20px;margin:24px 0;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:12px;font-weight:600;">Dados da obrigação</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;width:40%;">Emissão</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $emission?->name ?? 'Não informada' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Obrigação</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $obligation->title }}</td>
                    </tr>
                    @if (filled($obligation->description))
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;">Resumo</td>
                            <td style="padding:8px 0;">{{ \Illuminate\Support\Str::limit($obligation->description, 220) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Data de vencimento</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $obligation->due_date?->format('d/m/Y') ?? 'Não informada' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Status atual</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $obligation->status_label }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Responsável</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $obligation->responsibleUser?->name ?? $obligation->responsible_party ?? 'Não informado' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Prioridade</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $obligation->priority_label }}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align:center;margin:28px 0;">
                <a href="{{ $actionUrl }}" style="display:inline-block;background:#091b23;color:#e6e4e4;text-decoration:none;font-weight:600;padding:12px 28px;border-radius:10px;font-size:14px;">
                    Acessar no painel
                </a>
            </div>

            <p style="font-size:13px;color:#6b7280;margin-bottom:0;">
                O acesso ao painel exige autenticação. Caso não seja o responsável por esta obrigação, encaminhe este aviso à área competente.
            </p>
        </div>
        <div style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">
            Mensagem automática — não responda a este e-mail.
        </div>
    </div>
</body>
</html>
