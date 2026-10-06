<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Quita el carácter NUL (`\0`) de todo texto que llega a la API.
 *
 * PostgreSQL no admite NUL en un `text` ni en un `jsonb`. Según la columna, el
 * resultado era un 500 (el chat del tutor, que guarda los mensajes en `jsonb`) o
 * que el controlador de la base lo truncara en silencio justo antes del NUL — un
 * título «Examen\0Final» se guardaba como «Examen» sin que nadie lo notara. Ningún
 * texto legítimo lo lleva, así que se descarta a la entrada, igual que
 * `TrimStrings` descarta los espacios.
 */
class RemoveNullBytes extends TransformsRequest
{
    protected function transform($key, $value)
    {
        return is_string($value) ? str_replace("\0", '', $value) : $value;
    }
}
