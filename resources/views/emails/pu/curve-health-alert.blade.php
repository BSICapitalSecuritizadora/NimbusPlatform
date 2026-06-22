<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Alerta operacional — Calculadora de PU</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Helvetica,Arial,sans-serif;color:#333;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#091b23;color:#e6e4e4;padding:18px 22px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;font-size:18px;">Alerta operacional — Calculadora de PU</h1>
        </div>
        <div style="background:#ffffff;padding:22px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;">
            <p style="margin:0 0 12px;">Foram detectados problemas que exigem atenção na calculadora de PU:</p>

            <ul style="margin:0 0 18px;padding-left:18px;color:#b3261e;">
                @foreach ($issues as $issue)
                    <li style="margin-bottom:6px;">{{ $issue }}</li>
                @endforeach
            </ul>

            <h2 style="font-size:14px;color:#091b23;border-bottom:1px solid #a06e28;padding-bottom:4px;">Fila</h2>
            <p style="margin:6px 0;font-size:13px;">
                Jobs pendentes: <strong>{{ $queueMetrics['pending_jobs'] ?? 0 }}</strong><br>
                Jobs de PU com falha: <strong>{{ $queueMetrics['failed_pu_jobs'] ?? 0 }}</strong><br>
                Curvas travadas em processamento: <strong>{{ $queueMetrics['stuck_versions'] ?? 0 }}</strong>
            </p>

            <h2 style="font-size:14px;color:#091b23;border-bottom:1px solid #a06e28;padding-bottom:4px;">Curvas</h2>
            <p style="margin:6px 0;font-size:13px;">
                Homologadas: <strong>{{ $statusCounts['homologated'] ?? 0 }}</strong> ·
                Divergentes: <strong>{{ $statusCounts['divergent'] ?? 0 }}</strong> ·
                Erro: <strong>{{ $statusCounts['error'] ?? 0 }}</strong> ·
                Processando: <strong>{{ $statusCounts['processing'] ?? 0 }}</strong>
            </p>

            <p style="margin:18px 0 0;font-size:12px;color:#888;">
                Verifique o painel operacional (Gestão &gt; Painel Operacional de PU) e garanta que o worker de
                fila está ativo (<code>queue:work</code> / Supervisor / Horizon). Este alerta é reenviado quando o
                conjunto de problemas muda ou após o intervalo de cooldown configurado.
            </p>
        </div>
    </div>
</body>
</html>
