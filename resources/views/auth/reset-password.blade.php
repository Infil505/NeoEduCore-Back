{{--
    Formulario de restablecer contraseña, servido por el backend.

    **Excepción deliberada** a la regla de «el backend no sirve HTML» (la que
    mandó dejar los PDF y los gráficos en el frontend). El motivo: este
    formulario es el destino de un enlace enviado por correo y tiene que
    funcionar aunque el frontend no esté desplegado o cambie de dominio. Es el
    mismo criterio por el que las plantillas de correo también viven aquí.

    Autocontenida a propósito: sin CDN ni build. Envía el formulario contra
    `POST /api/password/reset`, que es donde vive la lógica.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Restablecer contraseña | {{ $appName }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #f4f6f8;
            color: #1f2937;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            padding: 32px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }
        h1 { font-size: 21px; margin: 0 0 8px; }
        .sub { font-size: 14px; color: #52514e; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
        input[type=password] {
            width: 100%;
            padding: 10px 12px;
            font-size: 15px;
            border: 1px solid #c3c2b7;
            border-radius: 6px;
            margin-bottom: 16px;
            background: #fff;
            color: inherit;
        }
        input:focus { outline: 2px solid #2563eb; outline-offset: 1px; }
        button {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: #2563eb;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }
        button:disabled { background: #9ca3af; cursor: not-allowed; }
        .reglas { font-size: 12px; color: #6b7280; margin: -8px 0 18px; }
        .msg { font-size: 14px; padding: 12px 14px; border-radius: 6px; margin: 0 0 18px; }
        .msg.error { background: #fef2f2; color: #991b1b; border-left: 3px solid #d03b3b; }
        .msg.ok    { background: #f0fdf4; color: #14532d; border-left: 3px solid #0ca30c; }
        .footer { margin-top: 22px; text-align: center; font-size: 12px; color: #9ca3af; }
        {{-- `@@media` y no `@media`: Blade interpreta `@media` como directiva
             suya y rompe la plantilla con un error de sintaxis. --}}
        @@media (prefers-color-scheme: dark) {
            body { background: #0d0d0d; color: #e5e5e5; }
            .card { background: #1a1a19; box-shadow: none; border: 1px solid #2c2c2a; }
            .sub, .reglas { color: #c3c2b7; }
            input[type=password] { background: #0d0d0d; border-color: #383835; color: #e5e5e5; }
        }
    </style>
</head>
<body>
<main class="card">
    <h1>Restablecer contraseña</h1>

    @if (! $valido)
        {{-- Enlace caducado, ya usado o manipulado. No se distingue cuál a
             propósito: decirlo daría información a quien no es el dueño. --}}
        <p class="sub">{{ $appName }}</p>
        <div class="msg error">
            Este enlace ya no es válido. Puede que haya caducado, que ya se haya
            usado o que esté incompleto.
        </div>
        <p class="sub" style="margin-bottom:0;">
            Solicitá uno nuevo desde la opción «Olvidé mi contraseña».
        </p>
    @else
        <p class="sub">Elegí una contraseña nueva para <strong>{{ $email }}</strong>.</p>

        <div id="aviso" class="msg error" style="display:none;" role="alert"></div>
        <div id="exito" class="msg ok" style="display:none;" role="status"></div>

        <form id="form" novalidate>
            <label for="password">Contraseña nueva</label>
            <input type="password" id="password" autocomplete="new-password" required>

            <p class="reglas">Mínimo 8 caracteres, con mayúscula, minúscula y número.</p>

            <label for="confirmacion">Repetir contraseña</label>
            <input type="password" id="confirmacion" autocomplete="new-password" required>

            <button type="submit" id="enviar">Guardar contraseña</button>
        </form>
    @endif

    <div class="footer">{{ $appName }} &copy; {{ date('Y') }}</div>
</main>

@if ($valido)
<script>
    // La directiva `@@json` escapa correctamente para incrustar en JS (evita
    // romper la página o inyectar código si el correo o el token traen
    // caracteres especiales). Va con doble arroba porque, si no, Blade también
    // compila la del comentario —sin argumentos— y revienta la plantilla.
    const ENDPOINT = @json($apiBaseUrl . '/password/reset');
    const EMAIL    = @json($email);
    const TOKEN    = @json($token);

    const form   = document.getElementById('form');
    const aviso  = document.getElementById('aviso');
    const exito  = document.getElementById('exito');
    const boton  = document.getElementById('enviar');

    function mostrarError(texto) {
        aviso.textContent = texto;
        aviso.style.display = 'block';
        exito.style.display = 'none';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const password = document.getElementById('password').value;
        const confirmacion = document.getElementById('confirmacion').value;

        if (password !== confirmacion) {
            mostrarError('Las dos contraseñas no coinciden.');
            return;
        }

        boton.disabled = true;
        boton.textContent = 'Guardando…';

        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    email: EMAIL,
                    token: TOKEN,
                    password: password,
                    password_confirmation: confirmacion,
                }),
            });

            const cuerpo = await res.json().catch(() => ({}));

            if (res.ok) {
                form.style.display = 'none';
                aviso.style.display = 'none';
                exito.textContent = 'Listo. Ya podés entrar con tu contraseña nueva.';
                exito.style.display = 'block';
                return;
            }

            // 422 trae los errores de validación campo a campo.
            const detalle = cuerpo.errors
                ? Object.values(cuerpo.errors).flat().join(' ')
                : (cuerpo.message || 'No se pudo cambiar la contraseña.');

            mostrarError(detalle);
        } catch (err) {
            mostrarError('No se pudo conectar con el servidor. Intentá de nuevo.');
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar contraseña';
        }
    });
</script>
@endif
</body>
</html>
