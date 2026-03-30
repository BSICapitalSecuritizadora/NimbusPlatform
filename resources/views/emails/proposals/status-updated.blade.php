<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Atualização da proposta</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#001233;border-radius:16px 16px 0 0;padding:28px 32px;color:#fff;">
            <div style="font-size:24px;font-weight:700;">BSI Capital</div>
            <div style="margin-top:8px;font-size:16px;">Atualização do andamento da sua proposta</div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <p style="margin-top:0;">Olá, {{ $proposal->contact->name }}.</p>
            <p>A proposta da empresa <strong>{{ $proposal->company->name }}</strong> teve uma atualização em nosso fluxo comercial.</p>

            <div style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:12px;padding:20px;margin:24px 0;">
                <div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:6px;">Status atual</div>
                <div style="font-size:28px;font-weight:700;color:#001233;">{{ \App\Models\Proposal::statusLabelFor($status) }}</div>
            </div>

            @if ($status === \App\Models\Proposal::STATUS_APPROVED)
                <p>Sua proposta foi aprovada pelo time comercial. Seguiremos com os próximos passos internos do processo.</p>
            @elseif ($status === \App\Models\Proposal::STATUS_REJECTED)
                <p>Sua proposta foi encerrada nesta etapa. Se necessário, nossa equipe comercial poderá orientar um novo envio futuro.</p>
            @elseif ($status === \App\Models\Proposal::STATUS_COMPLETED)
                <p>A análise comercial desta proposta foi concluída com sucesso.</p>
            @endif

            <p style="margin:24px 0 0;">Em caso de dúvidas, responda este e-mail ou fale com o time comercial da BSI Capital.</p>
        </div>
    </div>
</body>
</html>
