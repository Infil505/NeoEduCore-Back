<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Establecer contraseña | {{ $appName }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }
        .container {
            max-width: 480px;
            margin: 40px auto;
            background: #ffffff;
            padding: 32px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }
        h1 { font-size: 22px; margin: 0 0 16px; }
        p  { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .btn-wrap { text-align: center; margin: 28px 0; }
        a.btn {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
        }
        .fallback {
            font-size: 13px;
            color: #6b7280;
            word-break: break-all;
        }
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Establecé tu contraseña</h1>

    <p>Hola <strong>{{ $user->full_name ?? 'Usuario' }}</strong>,</p>

    <p>
        Se creó tu cuenta en <strong>{{ $appName }}</strong>. Para activarla y definir
        tu contraseña, hacé clic en el siguiente botón:
    </p>

    <div class="btn-wrap">
        <a class="btn" href="{{ $resetUrl }}">Establecer contraseña</a>
    </div>

    <p class="fallback">
        Si el botón no funciona, copiá y pegá este enlace en tu navegador:<br>
        <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
    </p>

    <p style="font-size:13px;color:#6b7280;">
        Por seguridad, este enlace caduca en 24 horas. Si no esperabas este correo,
        podés ignorarlo.
    </p>

    <div class="footer">
        {{ $appName }} © {{ date('Y') }}
    </div>
</div>
</body>
</html>
