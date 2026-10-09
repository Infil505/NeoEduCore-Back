<?php

namespace App\Services\Auth;

use App\Domain\Auth\ContrasenaTemporal;
use App\Enums\UserStatus;
use App\Mail\ContrasenaTemporalMail;
use App\Mail\PasswordSetupMail;
use App\Models\Admin\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Genera y envía un enlace para que el usuario establezca su contraseña.
 *
 * Reutiliza el mismo mecanismo de token que la recuperación de contraseña
 * (tabla `password_reset_tokens`), de modo que el enlace funciona con el flujo
 * de reset ya existente, pero **con su propio correo** (`PasswordSetupMail`):
 * el texto de «se creó tu cuenta» no vale para quien pidió recuperar la suya.
 * Se usa en el alta de usuarios por carga masiva, donde no se define una
 * contraseña en el archivo.
 */
class PasswordSetupService
{
    /**
     * Pone a la cuenta una contraseña TEMPORAL aleatoria, la deja activa con
     * `must_change_password` y se la manda por correo (decisión del 09/10/2026).
     *
     * Sustituye al enlace de «establece tu contraseña» en las altas: el usuario
     * entra ya con la temporal y la API le exige cambiarla antes de usar nada
     * más (`ExigeCambioDeClave`).
     *
     * **Lanza** si el correo no sale, a diferencia de `sendSetupLink`: lo llama
     * un job con reintentos y tiene que fallar para que se reintente. Cada
     * reintento genera una temporal nueva, así que la última es la válida. El
     * correo se envía directamente (no encolado) para que la contraseña no
     * quede en claro en la tabla `jobs`.
     */
    public function sendTemporaryPassword(User $user): void
    {
        $temporal = ContrasenaTemporal::generar();

        $user->forceFill([
            'password_hash'        => Hash::make($temporal),
            'status'               => UserStatus::Active->value,
            'must_change_password' => true,
        ])->save();

        // Sesiones previas fuera: la clave cambió.
        $user->tokens()->delete();

        Mail::to($user->email)->send(new ContrasenaTemporalMail($user, $temporal));
    }

    /**
     * Encola el correo con el enlace de "establece tu contraseña".
     * El envío real lo hace el worker de colas (PasswordResetMail es ShouldQueue),
     * así la request no se bloquea esperando al SMTP.
     *
     * Best-effort: si el encolado falla, lo reporta y devuelve false sin lanzar
     * (para no interrumpir un proceso por lotes).
     */
    public function sendSetupLink(User $user): bool
    {
        try {
            $tokenPlain = Str::random(64);

            // Un único token vigente por correo
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email'      => $user->email,
                'token'      => Hash::make($tokenPlain),
                'created_at' => now(),
            ]);

            // PasswordSetupMail y no PasswordResetMail: el mecanismo del token es
            // el mismo, pero el mensaje no. A este usuario le crearon la cuenta,
            // no pidió recuperar nada.
            Mail::to($user->email)->queue(new PasswordSetupMail($tokenPlain, $user));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
