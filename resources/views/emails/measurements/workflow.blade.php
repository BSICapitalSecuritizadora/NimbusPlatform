<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title }} - BSI Capital</title>
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

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ece9e8;">
        <tr>
            <td align="center" style="padding:40px 16px;">

                <!-- Main Card -->
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; border-radius:16px; overflow:hidden; box-shadow:0 20px 60px rgba(9,27,35,0.12), 0 4px 16px rgba(9,27,35,0.06);">

                    <!-- ═══════════════ HEADER ═══════════════ -->
                    <tr>
                        <td bgcolor="#091b23" style="background-color: #091b23; background: linear-gradient(135deg, #06151c 0%, #091b23 50%, #22424c 100%); padding:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="height:4px; background: linear-gradient(90deg, #7b541e, #b4864a, #7b541e);"></td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:40px 40px 36px 40px; text-align:center;">
                                        <img src="{{ asset('images/logo-bsi-email.png') }}"
                                             alt="BSI Capital Securitizadora"
                                             width="300"
                                             style="display:inline-block; max-width:300px; height:auto;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- ═══════════════ BODY ═══════════════ -->
                    <tr>
                        <td style="background-color:#ffffff; padding:0;">

                            <!-- Event banner -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:24px 40px; background-color:{{ $accent['soft'] }}; border-bottom:1px solid {{ $accent['border'] }};">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="vertical-align:middle; padding-right:14px;">
                                                    <div style="width:40px; height:40px; background:{{ $accent['accent'] }}; border-radius:10px; text-align:center; line-height:40px; font-size:20px; color:#ffffff;">
                                                        {!! $accent['icon'] !!}
                                                    </div>
                                                </td>
                                                <td style="vertical-align:middle;">
                                                    <div style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">
                                                        Acompanhamento de Medições
                                                    </div>
                                                    <div style="font-size:16px; font-weight:700; color:{{ $accent['text'] }}; line-height:1.3;">
                                                        {{ $title }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Greeting + Message -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:36px 40px 0 40px;">
                                        <p style="margin:0 0 20px 0; font-size:20px; font-weight:700; color:#091b23; line-height:1.3;">
                                            Olá, {{ $firstName }}!
                                        </p>
                                        @if ($description)
                                            <p style="margin:0 0 28px 0; font-size:15px; line-height:1.7; color:#4b5563;">
                                                {{ $description }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <!-- ─── DETAILS BOX ─── -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:0 40px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                                            <tr>
                                                <td style="background-color:#f9fafb; padding:14px 24px; border-bottom:1px solid #e5e7eb;">
                                                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.5px; color:#6b7280; font-weight:600;">
                                                        Detalhes da Medição
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="background-color:#ffffff; padding:8px 24px 16px 24px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280; width:40%;">Operação</td>
                                                            <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; font-size:14px; color:#111827; font-weight:600; text-align:right;">{{ $operationLabel }}</td>
                                                        </tr>
                                                        @if ($reference)
                                                            <tr>
                                                                <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280;">Competência</td>
                                                                <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; font-size:14px; color:#111827; font-weight:600; text-align:right;">{{ $reference }}</td>
                                                            </tr>
                                                        @endif
                                                        <tr>
                                                            <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280;">Etapa atual</td>
                                                            <td style="padding:12px 0; border-bottom:1px solid #f3f4f6; text-align:right;">
                                                                <span style="display:inline-block; background-color:{{ $accent['soft'] }}; color:{{ $accent['text'] }}; font-size:12px; font-weight:700; padding:4px 12px; border-radius:50px; border:1px solid {{ $accent['border'] }};">{{ $stageLabel }}</span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:12px 0; font-size:13px; color:#6b7280; vertical-align:top;">Arquivo</td>
                                                            <td style="padding:12px 0; font-size:13px; color:#111827; text-align:right; word-break:break-all;">{{ $filename }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            @if ($url)
                                <!-- ─── CTA BUTTON ─── -->
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="padding:32px 40px 0 40px; text-align:center;">
                                            <!--[if mso]>
                                            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:52px;v-text-anchor:middle;width:260px;" arcsize="50%" fillcolor="#091b23">
                                                <w:anchorlock/>
                                                <center style="color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;">Abrir medição &rarr;</center>
                                            </v:roundrect>
                                            <![endif]-->
                                            <!--[if !mso]><!-->
                                            <a href="{{ $url }}"
                                               target="_blank"
                                               style="display:inline-block; background-color: #091b23; background:linear-gradient(135deg, #06151c 0%, #091b23 100%); color:#e6e4e4; text-decoration:none; font-weight:700; font-size:15px; padding:16px 48px; border-radius:50px; letter-spacing:0.3px; box-shadow:0 4px 14px rgba(9,27,35,0.25); mso-hide:all;">
                                                Abrir medição &rarr;
                                            </a>
                                            <!--<![endif]-->
                                        </td>
                                    </tr>
                                </table>

                                <!-- Fallback link -->
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="padding:20px 40px 0 40px;">
                                            <p style="margin:0 0 8px 0; font-size:12px; color:#9ca3af; text-align:center;">
                                                Se o botão não funcionar, copie e cole este link no navegador:
                                            </p>
                                            <p style="margin:0; font-size:12px; word-break:break-all; text-align:center; line-height:1.5;">
                                                <a href="{{ $url }}" style="color:#2563eb; text-decoration:underline;">{{ $url }}</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:32px 40px 0 40px;">
                                        <p style="margin:0; font-size:14px; line-height:1.7; color:#4b5563; text-align:center;">
                                            Acesse o sistema para dar continuidade ao fluxo de acompanhamento.
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

                    <!-- ═══════════════ FOOTER ═══════════════ -->
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
                                            Todos os direitos reservados
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
                <!-- /Main Card -->

            </td>
        </tr>
    </table>

</body>
</html>
