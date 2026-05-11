<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Portal do Cliente - BSI Capital Securitizadora">
    <title>Portal do Cliente - BSI Capital Securitizadora</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --nd-navy-900: #06101c;
            --nd-navy-800: #0c1b2e;
            --nd-gold-500: #d4a84b;
            --nd-gold-600: #a67f3d;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.nd-portal-login {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--nd-navy-900) 0%, var(--nd-navy-800) 40%, #1a2f4e 100%);
            position: relative;
            overflow: hidden;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        
        /* Animated orbs */
        .nd-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            animation: orbFloat 15s ease-in-out infinite;
        }
        
        .nd-orb-1 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(212, 168, 75, 0.12) 0%, transparent 70%);
            top: -150px;
            right: -100px;
        }
        
        .nd-orb-2 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(45, 74, 115, 0.25) 0%, transparent 70%);
            bottom: -100px;
            left: -100px;
            animation-delay: 5s;
        }
        
        .nd-orb-3 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(212, 168, 75, 0.08) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: 10s;
        }
        
        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0); opacity: 0.6; }
            25% { transform: translate(30px, -20px); opacity: 0.8; }
            50% { transform: translate(-20px, 30px); opacity: 0.5; }
            75% { transform: translate(20px, 10px); opacity: 0.7; }
        }
        
        /* Glass card styling */
        .nd-portal-card {
            width: calc(100% - 2rem);
            max-width: 480px;
            background: rgba(12, 27, 46, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 28px;
            padding: 3rem;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            position: relative;
            z-index: 10;
            animation: cardSlideIn 0.5s ease-out;
        }
        
        @keyframes cardSlideIn {
            0% { opacity: 0; transform: translateY(20px) scale(0.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        /* Logo container */
        .nd-portal-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.03) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .nd-portal-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        /* Titles */
        .nd-portal-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
            margin-bottom: 2.5rem;
            letter-spacing: -0.02em;
        }
        
        /* Code input field */
        .nd-code-label {
            display: block;
            text-align: center;
            color: var(--nd-gold-500);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
        }
        
        .nd-code-input {
            width: 100%;
            height: 72px;
            background: rgba(0, 0, 0, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            color: #ffffff;
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: 0.25em;
            text-align: center;
            text-transform: uppercase;
            transition: all 0.3s ease;
        }
        
        .nd-code-input::placeholder {
            color: rgba(255, 255, 255, 0.25);
            letter-spacing: 0.2em;
        }
        
        .nd-code-input:hover {
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        .nd-code-input:focus {
            outline: none;
            background: rgba(0, 0, 0, 0.6);
            border-color: var(--nd-gold-500);
            box-shadow: 0 0 0 4px rgba(212, 168, 75, 0.15);
            transform: scale(1.01);
        }
        
        /* Submit button */
        .nd-portal-submit {
            width: 100%;
            height: 56px;
            margin-top: 2rem;
            background: linear-gradient(135deg, var(--nd-gold-500) 0%, var(--nd-gold-600) 100%);
            border: none;
            border-radius: 14px;
            color: #0c1b2e;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.9375rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            box-shadow: 0 4px 20px rgba(212, 168, 75, 0.3);
        }
        
        .nd-portal-submit:hover {
            background: linear-gradient(135deg, #e4c47a 0%, var(--nd-gold-500) 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(212, 168, 75, 0.4);
        }
        
        /* Security footer */
        .nd-security-footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
        }
        
        .nd-security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 50px;
            margin-bottom: 1rem;
        }
        
        .nd-security-badge svg {
            color: var(--nd-gold-500);
            width: 14px;
            height: 14px;
        }
        
        .nd-security-badge span {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .nd-security-text {
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.75rem;
            line-height: 1.6;
            margin: 0 auto;
        }
        
        /* Alerts */
        .nd-portal-alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .nd-portal-alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
    </style>
</head>
<body class="nd-portal-login">
    
    <!-- Animated Orbs -->
    <div class="nd-orb nd-orb-1"></div>
    <div class="nd-orb nd-orb-2"></div>
    <div class="nd-orb nd-orb-3"></div>
    
    <!-- Portal Card -->
    <div class="nd-portal-card">
        <!-- Logo -->
        <div class="nd-portal-logo">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width: 40px; color: white;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        </div>
        
        <!-- Titles -->
        <h1 class="nd-portal-title">Portal do Cliente</h1>
        
        <!-- Alerts -->
        @if ($errors->any())
            <div class="nd-portal-alert nd-portal-alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
                </svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif
        
        <!-- Form -->
        <form method="post" action="{{ route('nimbus.auth.verify.post') }}" autocomplete="off">
            @csrf
            
            <label for="access_code" class="nd-code-label">
                Código de Acesso
            </label>
            
            <input type="text"
                   class="nd-code-input"
                   id="access_code"
                   name="access_code"
                   placeholder="XXXX-XXXX-XXXX"
                   value="{{ old('access_code') }}"
                   autocomplete="off"
                   required
                   autofocus
                   maxlength="14">
            
            <button type="submit" class="nd-portal-submit">
                <span>Acessar Portal</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/>
                </svg>
            </button>
        </form>
        
        <!-- Security Footer -->
        <div class="nd-security-footer">
            <div class="nd-security-badge">
                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                </svg>
                <span>Conexão Segura</span>
            </div>
            <p class="nd-security-text">
                Ambiente protegido por criptografia de ponta a ponta. Seus dados estão seguros.
            </p>
        </div>
    </div>

    <!-- Scripts -->
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        // Access Code Formatter - Real-time formatting
        const codeInput = document.getElementById('access_code');
        
        codeInput.addEventListener('input', function(e) {
            let input = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            let formatted = '';
            if (input.length > 0) formatted += input.substring(0, 4);
            if (input.length > 4) formatted += '-' + input.substring(4, 8);
            if (input.length > 8) formatted += '-' + input.substring(8, 12);
            
            e.target.value = formatted;
        });
        
        // Paste handler for full code
        codeInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const clean = paste.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 12);
            
            let formatted = '';
            if (clean.length > 0) formatted += clean.substring(0, 4);
            if (clean.length > 4) formatted += '-' + clean.substring(4, 8);
            if (clean.length > 8) formatted += '-' + clean.substring(8, 12);
            
            this.value = formatted;
        });
    </script>
</body>
</html>
