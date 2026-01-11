<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Restablecer contraseña | {{ $appName }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 420px;
            margin: 80px auto;
            background: #ffffff;
            padding: 32px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        h1 {
            font-size: 22px;
            margin-bottom: 8px;
            text-align: center;
        }

        p.subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        input {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 16px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 14px;
        }

        button {
            width: 100%;
            background-color: #2563eb;
            color: #ffffff;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        button:disabled {
            background-color: #93c5fd;
            cursor: not-allowed;
        }

        .message {
            margin-top: 16px;
            font-size: 14px;
            text-align: center;
        }

        .message.error {
            color: #dc2626;
        }

        .message.success {
            color: #16a34a;
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
    <h1>Restablecer contraseña</h1>
    <p class="subtitle">
        Hola <strong>{{ $user->full_name ?? 'Usuario' }}</strong><br>
        Ingresá tu nueva contraseña
    </p>

    <form id="resetForm">
        @csrf

        {{-- Estos valores vienen del controller (ya validados) --}}
        <input type="hidden" id="email" value="{{ $email }}">
        <input type="hidden" id="token" value="{{ $token }}">

        <label for="password">Nueva contraseña</label>
        <input type="password" id="password" required minlength="8">

        <label for="password_confirmation">Confirmar contraseña</label>
        <input type="password" id="password_confirmation" required minlength="8">

        <button type="submit" id="submitBtn">Cambiar contraseña</button>
    </form>

    <div id="message" class="message"></div>

    <div class="footer">
        {{ $appName }} © {{ date('Y') }}
    </div>
</div>

<script>
    const API_BASE_URL = "{{ $apiBaseUrl }}";

    const form = document.getElementById('resetForm');
    const messageEl = document.getElementById('message');
    const button = document.getElementById('submitBtn');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        messageEl.textContent = '';
        messageEl.className = 'message';
        button.disabled = true;

        const payload = {
            email: document.getElementById('email').value,
            token: document.getElementById('token').value,
            password: document.getElementById('password').value,
            password_confirmation: document.getElementById('password_confirmation').value
        };

        try {
            const res = await fetch(`${API_BASE_URL}/password/reset`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();

            if (!res.ok) {
                throw new Error(data.message || 'Error al cambiar la contraseña');
            }

            messageEl.textContent = data.message || 'Contraseña actualizada correctamente';
            messageEl.classList.add('success');
            form.reset();

        } catch (err) {
            messageEl.textContent = err.message;
            messageEl.classList.add('error');
        } finally {
            button.disabled = false;
        }
    });
</script>

</body>
</html>
