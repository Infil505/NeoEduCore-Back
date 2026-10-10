<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\Admin\User;
use App\Services\Auth\PasswordSetupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Contraseña temporal por correo para una cuenta dada de alta (carga masiva,
 * alta individual sin clave, administrador de centro).
 *
 * El nombre se conserva por los trabajos ya encolados con él: antes mandaba un
 * enlace de «crea tu contraseña»; desde el 09/10/2026 manda una contraseña
 * temporal y la cuenta queda activa, obligada a cambiarla en su primer acceso.
 *
 * Va en cola y no dentro de la petición: generar la clave cuesta un hash bcrypt
 * (~180 ms con BCRYPT_ROUNDS=12) más el envío SMTP, y una carga de 300 cuentas
 * tardaba casi un minuto solo en eso. Encolar es una inserción en bloque; el
 * trabajo lo paga el worker.
 */
class EnviarEnlaceDeAlta implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Cola donde van los correos de alta. El worker la atiende **después** de
     * `default` (`queue:work --queue=default,correos`): una carga masiva de 200
     * correos (~4 s cada uno) no hace esperar a `ProcesarCargaMasivaEstudiantes`,
     * a los avisos ni al resto de trabajos. **El worker debe listarla** o los
     * correos no salen nunca.
     */
    public const COLA = 'correos';

    public function __construct(public readonly string $userId)
    {
        $this->onQueue(self::COLA);
    }

    public function handle(PasswordSetupService $passwordSetup): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);

        // Cuenta borrada entre el encolado y la ejecución.
        if ($user === null) {
            return;
        }

        // A una cuenta suspendida no se le manda acceso: la bloqueó un administrador.
        if ($user->status === UserStatus::Suspended) {
            return;
        }

        // Activa y sin marca: su dueño ya eligió su propia contraseña (p. ej. por
        // el enlace de recuperación) y no hay nada que entregarle.
        if ($user->status === UserStatus::Active && ! $user->must_change_password) {
            return;
        }

        // Lanza si el correo no sale: el job falla y se reintenta.
        $passwordSetup->sendTemporaryPassword($user);
    }

    /** Segundos entre reintentos: da tiempo a que el SMTP o la base se recuperen. */
    public function backoff(): array
    {
        return [30, 120];
    }
}
