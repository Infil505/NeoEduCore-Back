<?php

namespace App\Models\Admin;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token de Sanctum que no escribe `last_used_at` en cada petición.
 *
 * Sanctum ejecuta un `UPDATE personal_access_tokens SET last_used_at = now()`
 * en **cada** petición autenticada. Es un viaje a la base más —y de escritura—
 * en el camino caliente de toda la API: con la base remota son ~430 ms por
 * petición solo para anotar «sigo aquí».
 *
 * La única lectura de `last_used_at` es la caducidad por inactividad
 * (`AppServiceProvider::caducarSesionesInactivas`), que se mide en minutos
 * (`sanctum.inactivity_minutes`). Anotar el uso con una precisión de un minuto
 * da exactamente el mismo resultado, así que si el valor guardado es más
 * reciente que `sanctum.last_used_granularity` se omite la escritura.
 *
 * Solo se omite cuando lo único que cambia es `last_used_at`: cualquier otro
 * guardado del token (habilidades, nombre…) se hace igual que siempre.
 */
class AccessToken extends PersonalAccessToken
{
    // Sin esto Eloquent deduce `access_tokens` del nombre de la clase.
    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->soloCambiaElUltimoUso() && $this->usoRegistradoRecientemente()) {
            return true;
        }

        return parent::save($options);
    }

    private function soloCambiaElUltimoUso(): bool
    {
        return array_keys($this->getDirty()) === ['last_used_at'];
    }

    private function usoRegistradoRecientemente(): bool
    {
        $segundos = (int) config('sanctum.last_used_granularity');

        if ($segundos <= 0) {
            return false; // precisión total: como Sanctum de serie
        }

        $anterior = $this->getOriginal('last_used_at');

        return $anterior !== null
            && $this->asDateTime($anterior)->gt(now()->subSeconds($segundos));
    }
}
