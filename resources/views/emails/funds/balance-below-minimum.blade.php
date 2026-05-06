<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Saldo abaixo do minimo - BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#001233;border-radius:16px 16px 0 0;padding:28px 32px;color:#fff;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">Alerta automatico de saldo de fundo</div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Prezados(as),</p>
            <p>Informamos que o saldo do fundo vinculado a emissao <strong>{{ $fund->emission?->name ?? 'Nao informada' }}</strong> esta abaixo do valor minimo cadastrado.</p>

            <div style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:12px;padding:20px;margin:24px 0;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:12px;font-weight:600;">Dados do fundo</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;width:40%;">Nome da emissao</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $fund->emission?->name ?? 'Nao informada' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Numero da conta</td>
                        <td style="padding:8px 0;font-weight:600;">Conta {{ $fund->account }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Saldo atual</td>
                        <td style="padding:8px 0;font-weight:600;">R$ {{ \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($fund->balance) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Valor minimo cadastrado</td>
                        <td style="padding:8px 0;font-weight:600;">R$ {{ \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($fund->minimum_balance) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6b7280;">Data e hora da verificacao</td>
                        <td style="padding:8px 0;font-weight:600;">{{ $checkedAt->format('d/m/Y H:i') }}</td>
                    </tr>
                    @if (filled($fund->emission?->description))
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Observacao</td>
                            <td style="padding:8px 0;font-weight:600;">{{ $fund->emission->description }}</td>
                        </tr>
                    @endif
                </table>
            </div>

            <p style="margin-bottom:0;color:#6b7280;font-size:14px;">Solicitamos a verificacao do fundo e, se necessario, a adocao das providencias cabiveis.</p>
        </div>
        <div style="text-align:center;padding:20px 0;font-size:12px;color:#9ca3af;">
            BSI Capital Securitizadora S/A - Comunicacao Institucional
        </div>
    </div>
</body>
</html>
