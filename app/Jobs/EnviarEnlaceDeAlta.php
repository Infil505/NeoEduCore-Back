<?php

namespace App\Jobs;

use App\Models\Admin\User;
use App\Services\Auth\PasswordSetupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enlace de «crea tu contraseña» para una cuenta dada de alta por carga masiva.
 *
 * Va en cola y no dentro de la petición: generar el token cuesta un hash bcrypt
 * (~180 ms con BCRYPT_ROUNDS=12), y una carga de 300 cuentas tardaba casi un
 * minuto solo en eso —el navegador cortaba la petición antes de terminar—.
 * Encolar es una inserción por cuenta; el hash lo paga el worker.
 */
class EnviarEnlaceDeAlta implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $userId)
    {
    }

    public function handle(PasswordSetupService $passwordSetup): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);

        // Si la cuenta se borró, o ya está activa (fijó su contraseña por otra
        // vía) entre el encolado y la ejecución, no hay enlace que mandar.
        if ($user === null || $user->status->value !== 'inactive') {
            return;
        }

        $passwordSetup->sendSetupLink($user);
    }
}
