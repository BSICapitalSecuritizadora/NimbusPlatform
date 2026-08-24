<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>BSI Capital — Atualização da candidatura</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #091B23; line-height: 1.6;">
    <p>Olá, {{ $application->name }},</p>

    @if($application->status === \App\Models\JobApplication::STATUS_HIRED)
        <p>Agradecemos sua participação no processo seletivo para a vaga <strong>{{ $application->vacancy?->title ?? 'na BSI Capital' }}</strong>.</p>
        <p>Temos o prazer de informar que sua candidatura avançou para a etapa de contratação. Nossa equipe entrará em contato com as próximas instruções.</p>
    @elseif($application->status === \App\Models\JobApplication::STATUS_REJECTED)
        <p>Agradecemos seu interesse na vaga <strong>{{ $application->vacancy?->title ?? 'na BSI Capital' }}</strong> e o tempo dedicado ao processo seletivo.</p>
        <p>Após análise, optamos por seguir com outros perfis neste momento. Seu currículo permanecerá em nosso banco de talentos para futuras oportunidades, respeitando os prazos previstos em nossa Política de Privacidade.</p>
    @else
        <p>Houve uma atualização no status da sua candidatura para a vaga <strong>{{ $application->vacancy?->title ?? 'na BSI Capital' }}</strong>: <strong>{{ \App\Models\JobApplication::statusLabelFor($application->status) }}</strong>.</p>
    @endif

    <p>Em caso de dúvidas, responda diretamente a este e-mail.</p>

    <p style="margin-top: 24px; color: #6b7280; font-size: 12px;">
        Esta é uma mensagem automática. Os dados pessoais serão tratados conforme a Política de Privacidade da BSI Capital e a legislação aplicável.
    </p>

    <p style="color: #6b7280; font-size: 12px;">Atenciosamente,<br>Equipe BSI Capital</p>
</body>
</html>
