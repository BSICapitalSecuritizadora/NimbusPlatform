<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><title>Formulário de Contato</title></head>
<body style="font-family: sans-serif; color: #0b1220; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #091B23; border-bottom: 2px solid #A06E28; padding-bottom: 8px;">Nova mensagem via formulário de contato</h2>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tr>
            <td style="padding: 8px 0; font-weight: bold; width: 120px; color: #555;">Nome</td>
            <td style="padding: 8px 0;">{{ $data['name'] }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: bold; color: #555;">E-mail</td>
            <td style="padding: 8px 0;"><a href="mailto:{{ $data['email'] }}">{{ $data['email'] }}</a></td>
        </tr>
        @if(!empty($data['phone']))
        <tr>
            <td style="padding: 8px 0; font-weight: bold; color: #555;">Telefone</td>
            <td style="padding: 8px 0;">{{ $data['phone'] }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 8px 0; font-weight: bold; color: #555;">Assunto</td>
            <td style="padding: 8px 0;">{{ $data['subject'] }}</td>
        </tr>
    </table>

    <h3 style="color: #091B23; margin-top: 24px;">Mensagem</h3>
    <div style="background: #f5f5f5; padding: 16px; border-radius: 8px; white-space: pre-wrap;">{{ $data['message'] }}</div>

    <p style="margin-top: 32px; font-size: 12px; color: #999;">Enviado via formulário de contato do site BSI Capital — {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
