<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Atualização de proposta — BSI Capital</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#001233;border-radius:16px 16px 0 0;padding:28px 32px;color:#fff;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;opacity:.85;">Atualização de andamento — Proposta Comercial</div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Prezado(a) {{ $proposal->contact->name }},</p>
            <p>Informamos que a proposta da empresa <strong>{{ $proposal->company->name }}</strong> registrou uma atualização em nosso fluxo de análise comercial.</p>

            <div style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:12px;padding:20px;margin:24px 0;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:8px;font-weight:600;">Situação atual da proposta</div>
                <div style="font-size:26px;font-weight:700;color:#001233;">{{ \App\Enums\ProposalStatus::labelFor($status) }}</div>
            </div>

            @if ($status === \App\Enums\ProposalStatus::Approved->value)
                <p>Sua proposta foi aprovada pela equipe comercial da BSI Capital. Daremos continuidade às próximas etapas do processo internamente e entraremos em contato com as orientações necessárias.</p>
            @elseif ($status === \App\Enums\ProposalStatus::Rejected->value)
                <p>Após análise, sua proposta não avançou nesta etapa do processo. Caso julgue pertinente, nossa equipe comercial está à disposição para orientar sobre eventuais adequações e novas submissões.</p>
            @elseif ($status === \App\Enums\ProposalStatus::Completed->value)
                <p>A análise comercial desta proposta foi concluída. Agradecemos a confiança depositada na BSI Capital.</p>
            @endif

            <p style="margin:24px 0 0;color:#6b7280;font-size:14px;">Em caso de dúvidas, responda a este e-mail ou entre em contato diretamente com a equipe comercial da BSI Capital.</p>
        </div>
        <div style="text-align:center;padding:20px 0;font-size:12px;color:#9ca3af;">
            BSI Capital Securitizadora S/A — Comunicação Institucional
        </div>
    </div>
</body>
</html>
