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

    /** Columnas de `users` que se traen junto al token (sin prefijo en la tabla). */
    private const COLUMNAS_USUARIO = [
        'id', 'institution_id', 'email', 'password_hash', 'full_name', 'user_type',
        'status', 'remember_token', 'created_at', 'updated_at', 'must_change_password',
    ];

    /**
     * Busca el token Y a su usuario en UNA consulta.
     *
     * Sanctum hace dos viajes a la base en cada petición autenticada: uno para el
     * token y otro, perezoso, para su usuario (`tokenable`). Con la base remota
     * (~0,5 s por consulta) eso son ~1 s de cada petición solo para saber quién
     * llama, y toda la API pasa por aquí. Un `JOIN` trae las dos filas juntas y se
     * deja el usuario ya cargado en la relación.
     *
     * La validación es la de siempre: el hash del token se compara igual, y el
     * usuario sale de la misma tabla sin más filtros que antes. Un token con un
     * id que no es numérico se trata como inválido (antes Sanctum se lo pasaba a
     * PostgreSQL y fallaba con un 500).
     */
    public static function findToken($token)
    {
        if (strpos($token, '|') === false) {
            return parent::findToken($token);
        }

        [$id, $plano] = explode('|', $token, 2);

        if (! ctype_digit($id)) {
            return null;
        }

        $selecciona = ['personal_access_tokens.*'];
        foreach (self::COLUMNAS_USUARIO as $columna) {
            $selecciona[] = "users.{$columna} as u__{$columna}";
        }

        $fila = static::query()
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.id', $id)
            ->where('personal_access_tokens.tokenable_type', (new User())->getMorphClass())
            ->select($selecciona)
            ->toBase()
            ->first();

        if ($fila === null) {
            return null;
        }

        $token_ = [];
        $usuario = [];
        foreach ((array) $fila as $clave => $valor) {
            if (str_starts_with($clave, 'u__')) {
                $usuario[substr($clave, 3)] = $valor;
            } else {
                $token_[$clave] = $valor;
            }
        }

        $instancia = (new static())->newFromBuilder($token_);

        if (! hash_equals((string) $instancia->token, hash('sha256', $plano))) {
            return null;
        }

        return $instancia->setRelation('tokenable', (new User())->newFromBuilder($usuario));
    }

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
