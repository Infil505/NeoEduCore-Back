{{--
    Correo de ALTA DE CUENTA: al usuario se la crearon (carga masiva o alta
    desde administración) y tiene que establecer su primera contraseña.
    El de recuperación es `emails/password-reset.blade.php`.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Activá tu cuenta | {{ $appName }}</title>
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
        .fallback { font-size: 13px; color: #6b7280; word-break: break-all; }
        .aviso {
            font-size: 13px;
            color: #52514e;
            background: #f9f9f7;
            border-left: 3px solid #c3c2b7;
            padding: 12px 14px;
            margin: 0 0 16px;
        }
        .footer { margin-top: 24px; text-align: center; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="container">
    <h1>Activá tu cuenta</h1>

    <p>Hola <strong>{{ $user->full_name ?? 'Usuario' }}</strong>,</p>

    <p>
        Se creó tu cuenta en <strong>{{ $appName }}</strong>. Para activarla y
        definir tu contraseña, hacé clic en el botón:
    </p>

    <div class="btn-wrap">
        <a class="btn" href="{{ $setupUrl }}">Establecer contraseña</a>
    </div>

    <p class="fallback">
        Si el botón no funciona, copiá y pegá este enlace en tu navegador:<br>
        <a href="{{ $setupUrl }}">{{ $setupUrl }}</a>
    </p>

    <p class="aviso">
        Si no esperabas este correo, podés ignorarlo: la cuenta queda sin
        activar y nadie puede usarla sin acceso a tu buzón.
    </p>

    <p style="font-size:13px;color:#6b7280;">
        Por seguridad, este enlace caduca en {{ $horas }} horas y solo se puede usar una vez.
    </p>

    <div class="footer">
        {{ $appName }} © {{ date('Y') }}
    </div>
</div>
</body>
</html>
