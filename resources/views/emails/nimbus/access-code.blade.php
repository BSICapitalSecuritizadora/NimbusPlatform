<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Código de Acesso ao Portal - BSI Capital</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #f1f5f9;
            color: #333333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #06101c;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 3px solid #d4a84b;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: bold;
            color: #0c1b2e;
            margin-bottom: 20px;
        }
        .message {
            font-size: 16px;
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 30px;
        }
        .code-box {
            background-color: #f8fafc;
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 30px;
        }
        .code-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 10px;
            display: block;
        }
        .code-value {
            font-family: 'Courier New', Courier, monospace;
            font-size: 28px;
            font-weight: bold;
            color: #d4a84b;
            letter-spacing: 2px;
            margin: 0;
        }
        .btn-wrapper {
            text-align: center;
            margin-top: 30px;
        }
        .btn {
            display: inline-block;
            background-color: #d4a84b;
            color: #06101c;
            text-decoration: none;
            padding: 14px 30px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 50px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <!-- Em produção, use a URL da sua logo: src="{{ url('assets/images/logo-bsi.png') }}" -->
            <h1 style="color: #ffffff; letter-spacing: 1px;">BSI <span style="color: #d4a84b;">CAPITAL</span></h1>
        </div>
        
        <div class="content">
            <div class="greeting">Olá, {{ explode(' ', $user->full_name ?? 'Parceiro')[0] }}!</div>
            
            <p class="message">
                Para darmos andamento à sua solicitação e envio de documentos com toda a segurança, geramos
                o seu código único de validação. Ele é pessoal, intransferível e expirará em 7 dias.
            </p>
            
            <div class="code-box">
                <span class="code-label">Seu Código de Acesso</span>
                <p class="code-value">{{ $code }}</p>
            </div>
            
            <p class="message">
                Basta clicar no botão abaixo para acessar o seu Portal do Cliente e utilizar este código para entrar:
            </p>
            
            <div class="btn-wrapper">
                <a href="{{ url('/nimbus/login') }}" class="btn">Acessar o Portal</a>
            </div>
        </div>
        
        <div class="footer">
            Este é um e-mail automático. Por favor, não responda.<br>
            &copy; {{ date('Y') }} BSI Capital Securitizadora S/A. Todos os direitos reservados.
        </div>
    </div>
</body>
</html>
