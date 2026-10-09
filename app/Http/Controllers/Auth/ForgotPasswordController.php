<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\PasswordPolicy;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Jobs\EnviarEnlaceRecuperacion;
use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    /**
     * Vigencia del enlace, en minutos, desde `config/auth.php`.
     *
     * Vive ahí y no en una constante de esta clase para que sea configurable por
     * entorno (`AUTH_PASSWORD_RESET_EXPIRE_MINUTES`) y para no duplicar el dato:
     * los correos calculan de aquí el plazo que anuncian, así que no pueden
     * quedar desfasados respecto a lo que hace el código.
     */
    public static function minutosDeVigencia(): int
    {
        return (int) config('auth.passwords.users.expire', 60 * 24);
    }

    /**
     * ¿Caducó el token?
     *
     * Antes esto era `now()->diffInHours($createdAt) > 24`, repetido en tres
     * sitios, y **no caducaba nunca**: Laravel 12 monta Carbon 3, donde
     * `diffInHours` devuelve un float CON SIGNO en vez del valor absoluto de
     * Carbon 2. Para una fecha pasada el resultado es negativo (-48, -200…),
     * así que la comparación `> 24` jamás se cumplía y un enlace sin usar
     * servía indefinidamente.
     *
     * `addHours(...)->isPast()` no depende del signo ni del orden de los
     * operandos, así que no puede repetirse el fallo.
     */
    private function tokenCaducado(object $registro): bool
    {
        return Carbon::parse($registro->created_at)
            ->addMinutes(self::minutosDeVigencia())
            ->isPast();
    }

    /**
     * Busca el token del correo y lo valida (existe, coincide y no caduca).
     * Si caducó, lo borra. Devuelve null cuando no sirve.
     */
    private function tokenVigente(string $email, string $tokenPlano): ?object
    {
        $registro = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$registro || !Hash::check($tokenPlano, $registro->token)) {
            return null;
        }

        if ($this->tokenCaducado($registro)) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return null;
        }

        return $registro;
    }

    /**
     * 1) Enviar enlace de recuperación (correo)
     * Responde siempre genérico para evitar enumeración.
     */
    public function sendResetLink(Request $request)
{
    $data = $request->validate([
        'email' => ['required', 'email', 'max:120'],
    ]);

    $email = strtolower($data['email']);

    // Respuesta genérica (evita enumeración)
    $genericResponse = response()->json([
        'message' => 'Si el correo está registrado, recibirás un enlace de recuperación'
    ]);

    try {
        /*
         | La petición hace lo MISMO exista la cuenta o no: encolar un job con
         | el correo. Buscar al usuario, generar el token y enviar el enlace
         | ocurre en el worker, donde el tiempo no lo ve quien pregunta.
         |
         | Antes todo eso corría aquí, y aunque la respuesta era genérica el
         | tiempo delataba qué correos están dados de alta: primero 91 ms frente
         | a 3 ms (bcrypt solo para los registrados), y tras igualar el bcrypt,
         | todavía ~13 ms (el INSERT del token y el encolado del correo). Detalle
         | en `EnviarEnlaceRecuperacion`.
         */
        EnviarEnlaceRecuperacion::dispatch($email);

        return $genericResponse;

    } catch (\Throwable $e) {
        report($e);

        return response()->json(['message' => 'Error interno del servidor'], 500);
    }
}


    /**
     * 2) Mostrar formulario Blade (solo accesible con link del correo)
     * Ruta en web.php:
     *   GET /password/reset/{token}?email=...
     */
    public function showResetForm(string $token, Request $request)
    {
        $email = strtolower((string) $request->query('email'));

        // La vista se renderiza igual con el enlace caducado o manipulado: es la
        // que explica al usuario qué pasó. Devolver 403 dejaba una página de
        // error del framework, sin forma de pedir otro enlace.
        $valido = $email !== '' && $this->tokenVigente($email, $token) !== null;

        return view('auth.reset-password', [
            'token'      => $token,          // token plano (del enlace)
            'email'      => $email,
            'user'       => $valido ? User::where('email', $email)->first() : null,
            'valido'     => $valido,
            'appName'    => config('app.name'),
            'apiBaseUrl' => url('/api'),
        ]);
    }

    /**
     * 3) Verificar token por API (opcional)
     */
    public function verifyToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:120'],
        ]);

        $email = strtolower($data['email']);

        try {
            $registro = DB::table('password_reset_tokens')->where('email', $email)->first();

            if (!$registro || !Hash::check($data['token'], $registro->token)) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            if ($this->tokenCaducado($registro)) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return response()->json(['message' => 'El token de reset ha expirado'], 400);
            }

            return response()->json(['message' => 'Token válido']);

        } catch (\Throwable $e) {
            // Antes se tragaba la excepción sin dejar rastro: un fallo real
            // (BD caída, tabla ausente) era indistinguible de un token inválido.
            report($e);

            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * 4) Resetear contraseña (API)
     * Espera: email, token y password + password_confirmation
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email', 'max:120'],
            'token'    => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (!(new PasswordPolicy())->isValid($data['password'])) {
            return response()->json([
                'message' => 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.',
            ], 422);
        }

        $email = strtolower($data['email']);

        try {
            $registro = DB::table('password_reset_tokens')->where('email', $email)->first();

            if (!$registro || !Hash::check($data['token'], $registro->token)) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            if ($this->tokenCaducado($registro)) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return response()->json(['message' => 'El token de reset ha expirado'], 400);
            }

            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            // El esquema guarda el hash en `password_hash`, no en `password`.
            // Quien define su contraseña desde el enlace ya eligió la suya: deja
            // de ser temporal.
            $cambios = ['password_hash' => Hash::make($data['password']), 'must_change_password' => false];

            /*
             | **Aquí se activa la cuenta.** Es el único punto del sistema donde
             | una cuenta pasa a `active` por acción de su dueño: definir una
             | contraseña usable desde el enlace que le llegó por correo prueba
             | que controla ese buzón.
             |
             | Solo desde `inactive`. Una cuenta `suspended` no se reactiva por
             | esta vía —la bloqueó un administrador—, aunque de hecho tampoco
             | llega hasta aquí, porque a las suspendidas no se les envía enlace.
             */
            if ($user->status === UserStatus::Inactive) {
                $cambios['status'] = UserStatus::Active->value;
            }

            $user->update($cambios);

            // Consumir token: un enlace sirve una sola vez.
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Revocar las sesiones abiertas: si el reset viene de una cuenta
            // comprometida, el atacante pierde el acceso que tuviera.
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return response()->json(['message' => 'Contraseña actualizada correctamente']);

        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * 5) Cambiar contraseña estando autenticado (API)
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (!(new PasswordPolicy())->isValid($data['password'])) {
            return response()->json([
                'message' => 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.',
            ], 422);
        }

        $user = $request->user();

        // ✅ Ajuste clave: comparar contra password_hash
        if (!Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta'], 400);
        }

        // La nueva no puede ser la actual: con una contraseña temporal sería
        // «cambiarla» por la misma y el cambio obligatorio no serviría de nada.
        if (Hash::check($data['password'], $user->password_hash)) {
            return response()->json(['message' => 'La nueva contraseña debe ser distinta de la actual.'], 422);
        }

        $user->update([
            'password_hash' => Hash::make($data['password']),
            // Ya es una contraseña elegida por su dueño: se levanta el bloqueo.
            'must_change_password' => false,
        ]);

        // Revocar tokens excepto el actual (si existe)
        if (method_exists($user, 'tokens')) {
            $currentToken = $user->currentAccessToken();
            if ($currentToken) {
                $user->tokens()->where('id', '!=', $currentToken->id)->delete();
            }
        }

        return response()->json(['message' => 'Contraseña cambiada correctamente']);
    }
}