<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Código de acesso à operação - BSI Capital</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-rspace: 0pt; mso-table-lspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #ece9e8; }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#ece9e8; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif; -webkit-font-smoothing:antialiased;">
    @php
        $firstName = explode(' ', trim((string) $access->requester_name))[0] ?: 'Parceiro';
    @endphp

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ece9e8;">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; border-radius:16px; overflow:hidden; box-shadow:0 20px 60px rgba(9,27,35,0.12), 0 4px 16px rgba(9,27,35,0.06);">
                    <tr>
                        <td bgcolor="#091b23" style="background-color:#091b23; background:linear-gradient(135deg, #06151c 0%, #091b23 52%, #22424c 100%); padding:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="height:4px; background:linear-gradient(90deg, #7b541e, #b4864a, #7b541e);"></td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:40px 40px 18px 40px; text-align:center;">
                                        <img
                                            src="{{ asset('images/logo-bsi-email.png') }}"
                                            alt="BSI Capital Securitizadora"
                                            width="300"
                                            style="display:inline-block; max-width:300px; height:auto;"
                                        >
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 40px 36px 40px; text-align:center;">
                                        <div style="font-size:28px; font-weight:700; line-height:1.25; color:#f6f4f3; margin-bottom:10px;">
                                            Código de acesso para consulta da operação
                                        </div>
                                        <div style="font-size:15px; line-height:1.7; color:#d8dddf;">
                                            Um fluxo dedicado para liberar a visualização técnica e documental da emissão com rastreabilidade e controle.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color:#ffffff; padding:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:24px 40px; background-color:#f4eee6; border-bottom:1px solid #d7c09c;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td style="vertical-align:middle; padding-right:14px;" width="54">
                                                    <div style="width:40px; height:40px; background:linear-gradient(135deg, #b4864a 0%, #a06e28 100%); border-radius:10px; text-align:center; line-height:40px; font-size:20px;">
                                                        &#128274;
                                                    </div>
                                                </td>
                                                <td style="vertical-align:middle;">
                                                    <div style="font-size:13px; font-weight:700; color:#1f2937; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">
                                                        Consulta controlada de operação
                                                    </div>
                                                    <div style="font-size:13px; color:#6b7280; line-height:1.4;">
                                                        Liberação de acesso mediante validação do código enviado para o e-mail informado.
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:36px 40px 0 40px;">
                                        <p style="margin:0 0 18px 0; font-size:20px; font-weight:700; color:#091b23; line-height:1.3;">
                                            Olá, {{ $firstName }}!
                                        </p>
                                        <p style="margin:0 0 14px 0; font-size:15px; line-height:1.7; color:#4b5563;">
                                            Recebemos sua solicitação para visualizar os dados completos da operação <strong style="color:#1f2937;">{{ $emission->name }}</strong>.
                                        </p>
                                        <p style="margin:0 0 28px 0; font-size:15px; line-height:1.7; color:#4b5563;">
                                            Para liberar o acesso, utilize o link seguro abaixo e informe o código exclusivo desta solicitação.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:0 40px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; overflow:hidden; border:1px solid #e5e7eb; box-shadow:0 8px 24px rgba(9,27,35,0.06);">
                                            <tr>
                                                <td style="background-color:#f9fafb; padding:14px 24px; border-bottom:1px solid #e5e7eb;">
                                                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.5px; color:#6b7280; font-weight:700; text-align:center;">
                                                        Seu código de acesso
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="background-color:#ffffff; padding:28px 24px; text-align:center;">
                                                    <div style="font-family:'Courier New',Courier,monospace; font-size:34px; font-weight:700; color:#091b23; letter-spacing:4px; line-height:1;">
                                                        {{ $code }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="background-color:#fefce8; padding:12px 24px; border-top:1px solid #fef08a;">
                                                    <div style="font-size:12px; color:#854d0e; text-align:center; line-height:1.6;">
                                                        &#9200;&nbsp;Válido até <strong>{{ $access->expires_at->format('d/m/Y') }}</strong> às <strong>{{ $access->expires_at->format('H:i') }}</strong>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:32px 40px 0 40px; text-align:center;">
                                        <p style="margin:0 0 24px 0; font-size:15px; line-height:1.7; color:#4b5563;">
                                            Ao validar o código, você terá acesso ao detalhamento da estrutura, documentos públicos e informações relevantes da operação.
                                        </p>
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $accessUrl }}" style="height:52px;v-text-anchor:middle;width:320px;" arcsize="50%" fillcolor="#091b23">
                                            <w:anchorlock/>
                                            <center style="color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;">Validar acesso à operação &rarr;</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <a
                                            href="{{ $accessUrl }}"
                                            target="_blank"
                                            style="display:inline-block; background-color:#091b23; background:linear-gradient(135deg, #06151c 0%, #091b23 100%); color:#e6e4e4; text-decoration:none; font-weight:700; font-size:15px; padding:16px 44px; border-radius:50px; letter-spacing:0.3px; box-shadow:0 4px 14px rgba(9,27,35,0.25); mso-hide:all;"
                                        >
                                            Validar acesso à operação &rarr;
                                        </a>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:32px 40px 0 40px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fbfaf9; border:1px solid #e5e7eb; border-radius:14px;">
                                            <tr>
                                                <td style="padding:22px 24px 8px 24px;">
                                                    <div style="font-size:12px; text-transform:uppercase; letter-spacing:1.4px; color:#6b7280; font-weight:700;">
                                                        Resumo da operação
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:0 24px 22px 24px;">
                                                    <div style="font-size:18px; font-weight:700; color:#091b23; line-height:1.4; margin-bottom:16px;">
                                                        {{ $emission->name }}
                                                    </div>
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td width="50%" style="padding:0 12px 14px 0; vertical-align:top;">
                                                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.1px; color:#8b949a; font-weight:700; margin-bottom:6px;">Código IF</div>
                                                                <div style="font-size:15px; font-weight:700; color:#1f2937;">{{ $emission->if_code ?? '—' }}</div>
                                                            </td>
                                                            <td width="50%" style="padding:0 0 14px 12px; vertical-align:top;">
                                                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.1px; color:#8b949a; font-weight:700; margin-bottom:6px;">Tipo</div>
                                                                <div style="font-size:15px; font-weight:700; color:#1f2937;">{{ $emission->type ?? '—' }}</div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td width="50%" style="padding:0 12px 14px 0; vertical-align:top;">
                                                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.1px; color:#8b949a; font-weight:700; margin-bottom:6px;">Emissor</div>
                                                                <div style="font-size:15px; font-weight:700; color:#1f2937;">{{ $emission->issuer ?? '—' }}</div>
                                                            </td>
                                                            <td width="50%" style="padding:0 0 14px 12px; vertical-align:top;">
                                                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.1px; color:#8b949a; font-weight:700; margin-bottom:6px;">Vencimento</div>
                                                                <div style="font-size:15px; font-weight:700; color:#1f2937;">{{ $emission->maturity_date?->format('d/m/Y') ?? '—' }}</div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:0;">
                                                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.1px; color:#8b949a; font-weight:700; margin-bottom:6px;">Remuneração</div>
                                                                <div style="font-size:15px; font-weight:700; color:#1f2937;">{{ $emission->formatted_remuneration ?? '—' }}</div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:32px 40px 0 40px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8fafc; border-radius:12px; border:1px solid #e5e7eb;">
                                            <tr>
                                                <td style="padding:20px 24px;">
                                                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#6b7280; margin-bottom:12px;">
                                                        &#128161; Orientações de segurança
                                                    </div>
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="padding:4px 0; font-size:13px; color:#4b5563; line-height:1.6;">
                                                                <span style="color:#a06e28; font-weight:bold; margin-right:6px;">&#10003;</span>
                                                                Nunca compartilhe este código com terceiros.
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:4px 0; font-size:13px; color:#4b5563; line-height:1.6;">
                                                                <span style="color:#a06e28; font-weight:bold; margin-right:6px;">&#10003;</span>
                                                                Utilize somente o link oficial recebido neste e-mail para validar o acesso.
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:4px 0; font-size:13px; color:#4b5563; line-height:1.6;">
                                                                <span style="color:#a06e28; font-weight:bold; margin-right:6px;">&#10003;</span>
                                                                Caso o botão não funcione, copie e cole o link abaixo no navegador.
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:24px 40px 0 40px;">
                                        <p style="margin:0 0 8px 0; font-size:12px; color:#9ca3af; text-align:center;">
                                            Se o botão não funcionar, copie e cole este link no navegador:
                                        </p>
                                        <p style="margin:0; font-size:12px; color:#091b23; word-break:break-all; text-align:center; line-height:1.6;">
                                            <a href="{{ $accessUrl }}" style="color:#2563eb; text-decoration:underline;">{{ $accessUrl }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:36px 0 0 0;"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color:#f9fafb; border-top:1px solid #e5e7eb; padding:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:24px 40px; text-align:center;">
                                        <p style="margin:0 0 8px 0; font-size:12px; color:#9ca3af; line-height:1.5;">
                                            Este é um e-mail automático. Por favor, não responda.
                                        </p>
                                        <p style="margin:0 0 4px 0; font-size:12px; color:#9ca3af; line-height:1.5;">
                                            &copy; {{ date('Y') }} BSI Capital Securitizadora S/A
                                        </p>
                                        <p style="margin:0; font-size:11px; color:#c4c8cc;">
                                            Comunicação institucional para validação de acesso a operações públicas
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
