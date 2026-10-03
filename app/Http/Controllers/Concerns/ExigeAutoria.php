<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;

/**
 * Un docente solo modifica o borra lo que él creó (decisión S6).
 *
 * El calendario y la biblioteca de recursos son del centro en cuanto a
 * **lectura** —todo el mundo los ve— pero cada entrada tiene dueño: quien la
 * creó. Hasta el 13/09/2026 `PUT` y `DELETE` de `calendar-events` y
 * `study-resources` no miraban la autoría, así que cualquier docente podía
 * reescribir el examen que otro había puesto en el calendario, o borrarle un
 * material. No era fuga de datos —el tenant estaba cerrado— pero sí una puerta
 * abierta sobre trabajo ajeno, y se cierra.
 *
 * **El administrador queda fuera de la regla**: responde por la institución
 * entera y necesita poder ordenar el calendario o retirar un recurso cuando
 * quien lo subió ya no está.
 *
 * **Sin autor conocido, solo el administrador.** `created_by` es nullable y
 * queda en `NULL` si se borra la cuenta que lo creó (`ON DELETE SET NULL`).
 * Dejar que cualquier docente edite esas entradas huérfanas reabriría el mismo
 * agujero por la puerta de atrás.
 */
trait ExigeAutoria
{
    /**
     * @param  object|null  $usuario     quien intenta la operación
     * @param  string|null  $creadorId   `created_by` de la entrada
     * @param  string       $queEs       cómo nombrarlo en el mensaje de error
     */
    protected function esSuyoOEsAdmin(?object $usuario, ?string $creadorId, string $queEs): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($usuario->user_type !== UserType::Teacher) {
            return true;
        }

        return $creadorId !== null && $creadorId === $usuario->id;
    }

    /** Respuesta 403 uniforme para cuando la autoría no coincide. */
    protected function noAutorizadoPorAutoria(string $queEs)
    {
        return response()->json([
            'message' => "No autorizado: {$queEs} lo creó otra persona.",
        ], 403);
    }
}
