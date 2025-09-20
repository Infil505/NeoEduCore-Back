<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .content {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }

        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            text-decoration: none;
            padding: 15px 30px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
            width: 100%;
            text-align: center;
        }

        .button:hover {
            transform: translateY(-2px);
        }

        .button:disabled {
            opacity: 0.6;
            transform: none;
            cursor: not-allowed;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }

        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .warning {
            background: #fef3cd;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }

        .footer {
            background: #f8fafc;
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            font-size: 14px;
            color: #64748b;
        }

        .url-break {
            word-break: break-all;
            color: #3b82f6;
        }

        .reset-form {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .hidden {
            display: none;
        }

        .strength-indicator {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            transition: width 0.3s, background-color 0.3s;
            width: 0%;
            background: #ef4444;
        }

        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">🔐 Recuperar Contraseña</h1>
        </div>

        <div class="content">
            <p>Hola <strong>{{ $user->name ?? 'Usuario' }}</strong>,</p>

            <p>Hemos recibido una solicitud para restablecer la contraseña de tu cuenta en
                <strong>{{ $appName }}</strong>.</p>

            <!-- Mensaje de alerta -->
            <div id="alertContainer"></div>

            <!-- Formulario de Reset Directo -->
            <div class="reset-form">
                <h3 style="margin-top: 0; color: #374151;">✏️ Crear Nueva Contraseña</h3>
                <p style="color: #6b7280; font-size: 14px;">Ingresa tu nueva contraseña directamente aquí:</p>

                <form id="resetForm">
                    <input type="hidden" id="userEmail" value="{{ $user->email }}">
                    <input type="hidden" id="resetToken" value="{{ $token }}">

                    <div class="form-group">
                        <label for="newPassword">Nueva Contraseña:</label>
                        <input type="password" id="newPassword" name="password" class="form-control" required
                            minlength="8" placeholder="Mínimo 8 caracteres">
                        <div class="strength-indicator">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirmar Nueva Contraseña:</label>
                        <input type="password" id="confirmPassword" name="password_confirmation" class="form-control"
                            required placeholder="Repite la nueva contraseña">
                    </div>

                    <button type="submit" class="button" id="resetSubmitBtn">
                        🔑 Cambiar Contraseña
                    </button>
                </form>
            </div>

            <!-- Opción alternativa con enlace -->
            <div style="text-align: center; margin: 20px 0;">
                <p style="color: #6b7280;">¿Prefieres usar el enlace tradicional?</p>
                <a href="{{ $resetUrl }}" class="button" style="width: auto; display: inline-block;">
                    🌐 Ir a la Página de Reset
                </a>
            </div>

            <div class="warning">
                <strong>⚠️ Información importante:</strong>
                <ul style="margin: 10px 0;">
                    <li>Este enlace expirará en <strong>24 horas</strong></li>
                    <li>Si no solicitaste este cambio, puedes ignorar este correo</li>
                    <li>Tu contraseña actual seguirá siendo válida hasta que la cambies</li>
                    <li>Nunca compartas este enlace con nadie</li>
                    <li>Este formulario solo funciona si tienes JavaScript habilitado</li>
                </ul>
            </div>

            <p>Si tienes problemas o preguntas, no dudes en contactarnos.</p>

            <p>Saludos,<br>
                El equipo de {{ $appName }}</p>
        </div>

        <div class="footer">
            <p><strong>¿Problemas con el formulario?</strong></p>
            <p>También puedes usar este enlace directo:</p>
            <p class="url-break">{{ $resetUrl }}</p>

            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">

            <p style="margin: 0;">
                Este correo fue enviado automáticamente desde {{ $appName }}.
                <br>Por favor, no respondas a este mensaje.
            </p>
        </div>
    </div>

    <script>
        // Configuración de la API - ¡IMPORTANTE! Cambiar por tu URL real
        const API_BASE_URL = 'http://localhost:8000/api'; // Cambiar por tu URL de API

        // Elementos del DOM
        const resetForm = document.getElementById('resetForm');
        const newPasswordInput = document.getElementById('newPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const alertContainer = document.getElementById('alertContainer');
        const submitBtn = document.getElementById('resetSubmitBtn');

        // Manejar envío del formulario
        resetForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const email = document.getElementById('userEmail').value;
            const token = document.getElementById('resetToken').value;
            const password = newPasswordInput.value;
            const passwordConfirmation = confirmPasswordInput.value;

            // Validaciones
            if (password !== passwordConfirmation) {
                showAlert('error', '❌ Las contraseñas no coinciden');
                return;
            }

            if (password.length < 8) {
                showAlert('error', '❌ La contraseña debe tener al menos 8 caracteres');
                return;
            }

            // Deshabilitar botón
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Cambiando Contraseña...';

            try {
                const response = await fetch(`${API_BASE_URL}/auth/reset-password`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        email: email,
                        token: token,
                        password: password,
                        password_confirmation: passwordConfirmation
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('success', '✅ ' + result.message);
                    resetForm.reset();

                    // Mensaje adicional de éxito
                    setTimeout(() => {
                        showAlert('success', '🎉 ¡Contraseña actualizada! Ya puedes iniciar sesión con tu nueva contraseña.');
                    }, 2000);

                } else {
                    showAlert('error', '❌ ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('error', '❌ Error de conexión. Verifica tu internet o usa el enlace directo abajo.');
            } finally {
                // Rehabilitar botón
                submitBtn.disabled = false;
                submitBtn.innerHTML = '🔑 Cambiar Contraseña';
            }
        });

        // Indicador de fortaleza de contraseña
        newPasswordInput.addEventListener('input', function () {
            const password = this.value;
            const strength = calculatePasswordStrength(password);

            updateStrengthIndicator(strength);
        });

        // Validación en tiempo real de confirmación
        confirmPasswordInput.addEventListener('input', function () {
            const password = newPasswordInput.value;
            const confirmation = this.value;

            if (confirmation && password !== confirmation) {
                this.style.borderColor = '#ef4444';
                this.style.backgroundColor = '#fef2f2';
            } else if (confirmation && password === confirmation) {
                this.style.borderColor = '#10b981';
                this.style.backgroundColor = '#f0fdf4';
            } else {
                this.style.borderColor = '#e5e7eb';
                this.style.backgroundColor = 'white';
            }
        });

        // Calcular fortaleza de contraseña
        function calculatePasswordStrength(password) {
            let strength = 0;

            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 25;
            if (/[a-z]/.test(password)) strength += 12.5;
            if (/[A-Z]/.test(password)) strength += 12.5;
            if (/[0-9]/.test(password)) strength += 12.5;
            if (/[^A-Za-z0-9]/.test(password)) strength += 12.5;

            return Math.min(strength, 100);
        }

        // Actualizar indicador de fortaleza
        function updateStrengthIndicator(strength) {
            strengthBar.style.width = strength + '%';

            let color, text;
            if (strength < 25) {
                color = '#ef4444';
                text = 'Muy débil';
            } else if (strength < 50) {
                color = '#f59e0b';
                text = 'Débil';
            } else if (strength < 75) {
                color = '#eab308';
                text = 'Buena';
            } else {
                color = '#10b981';
                text = 'Muy fuerte';
            }

            strengthBar.style.backgroundColor = color;
            strengthText.textContent = text;
            strengthText.style.color = color;
        }

        // Mostrar alertas
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = message;

            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);

            // Scroll suave hacia la alerta
            alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Auto-remover alertas de éxito después de 8 segundos
            if (type === 'success') {
                setTimeout(() => {
                    alertDiv.remove();
                }, 8000);
            }
        }

        // Verificar que el token sea válido al cargar
        document.addEventListener('DOMContentLoaded', function () {
            const email = document.getElementById('userEmail').value;
            const token = document.getElementById('resetToken').value;

            // Verificar token al cargar
            verifyToken(token, email);
        });

        // Verificar validez del token
        async function verifyToken(token, email) {
            try {
                const response = await fetch(`${API_BASE_URL}/auth/verify-reset-token`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ token, email })
                });

                const result = await response.json();

                if (!result.success) {
                    showAlert('error', '⚠️ Este enlace de recuperación es inválido o ha expirado. Por favor, solicita uno nuevo.');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '❌ Enlace Expirado';
                }
            } catch (error) {
                showAlert('error', '⚠️ No se pudo verificar el enlace. Puedes intentar usar el formulario o el enlace directo.');
            }
        }
    </script>
</body>

</html>