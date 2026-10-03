<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Mail\PasswordResetMail;
use App\Mail\PasswordSetupMail;
use App\Models\Admin\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Todo el trabajo de `POST /password/forgot` que depende de si la cuenta
 * existe, fuera de la petición (O6).
 *
 * La respuesta siempre fue genérica, pero el TIEMPO delataba qué correos están
 * dados de alta. Primero era un bcrypt que solo pagaban los registrados (91 ms
 * frente a 3 ms); se igualó haciendo el hash siempre, y aun así quedaban ~13 ms
 * de diferencia: el INSERT del token y el encolado del correo. Mientras la
 * petición haga algo distinto según exista la cuenta, cronometrándola se puede
 * distinguir.
 *
 * Ahora la petición solo valida y encola este job, exista la cuenta o no:
 * mismo trabajo en los dos caminos. Aquí, en el worker, el tiempo no lo ve
 * nadie, así que con un correo desconocido se sale sin más.
 *
 * Solo viaja el correo: ni el usuario ni el token se serializan en la cola.
 * Requiere `queue:work`, igual que el análisis de IA desde D1.
 */
class EnviarEnlaceRecuperacion implements ShouldQueue
{
    use Queueable;

    /** Un fallo del SMTP lo reintenta el propio Mailable, que también va a la cola. */
    public int $tries = 3;

    public function __construct(public readonly string $email)
    {
    }

    public function handle(): void
    {
        $user = User::where('email', $this->email)->first();

        /*
         | Se envía tanto a cuentas activas como a las que están INACTIVAS, y
         | nunca a las suspendidas.
         |
         | «Inactiva» significa aquí «dada de alta pero su dueño todavía no ha
         | definido contraseña». Si no se les enviara, quien perdiera el correo
         | de alta quedaría bloqueado para siempre, sin más salida que pedirle
         | al administrador que lo reenvíe.
         |
         | «Suspendida» es otra cosa: la bloqueó un administrador a propósito, y
         | un enlace de recuperación sería una vía para volver a entrar.
         */
        if ($user === null || $user->status === UserStatus::Suspended) {
            return;
        }

        $tokenPlain = Str::random(64);

        // Un enlace nuevo invalida el anterior: solo vale el último pedido.
        DB::table('password_reset_tokens')->where('email', $this->email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email'      => $this->email,
            'token'      => Hash::make($tokenPlain),
            'created_at' => now(),
        ]);

        // El correo que toca según el caso: quien nunca activó su cuenta
        // recibe «activá tu cuenta», no «recuperar contraseña» — que le
        // hablaría de una contraseña que nunca llegó a tener.
        $correo = $user->status === UserStatus::Inactive
            ? new PasswordSetupMail($tokenPlain, $user)
            : new PasswordResetMail($tokenPlain, $user);

        Mail::to($this->email)->queue($correo);
    }
}
