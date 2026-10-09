<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de datos que casi no cambian (materias, aulas, docentes, asignaciones,
 * configuración del centro), con invalidación por VERSIÓN.
 *
 * Con la base remota cada consulta cuesta ~0,5 s, y estos datos se piden en
 * casi cada pantalla y por cada usuario. Se guardan por institución y área:
 *
 *   tc:{centro}:{área}:{versión}:{clave}
 *
 * Invalidar un área NO borra entradas: sube su versión, y las claves viejas
 * dejan de leerse (caducan solas por TTL). Así no hace falta saber qué claves
 * existen —varían por rol, por docente y por filtros— y la invalidación sirve
 * entre contenedores (API, worker de cola) siempre que compartan almacén.
 *
 * **Reglas para quien cachee algo aquí:**
 *  - la clave tiene que incluir TODO lo que cambia el resultado: filtros, página
 *    y, si el alcance depende del usuario (docente), el id del usuario. Nunca se
 *    comparte un resultado entre dos usuarios con alcance distinto;
 *  - se cachea el resultado ya convertido a arrays, no consultas ni modelos;
 *  - toda escritura que pueda cambiarlo invalida su área. Las que pasan por
 *    Eloquent lo hacen solas (observadores en `AppServiceProvider`); las que usan
 *    `DB::table()` o el query builder no disparan eventos y llaman a
 *    `invalidar()` a mano.
 *
 * **Almacén.** En producción API y worker son contenedores distintos: con
 * `CACHE_STORE=file` cada uno tendría su caché y una invalidación hecha en el
 * worker no llegaría a la API. Hace falta un almacén compartido (Redis). Con
 * `database` funciona, pero cada lectura es una consulta a la misma base que se
 * quiere aliviar.
 */
final class TenantCache
{
    /** Datos del catálogo: materias, aulas, docentes, asignaciones. */
    public const CATALOGO = 'catalogo';

    /** Ajustes del centro (`institutions.settings`). */
    public const CONFIG = 'config';

    /**
     * Avisos del calendario que ve el alumnado, compartidos por aula. Depende de
     * los avisos, de los exámenes y las aulas que muestran, y del recuento de
     * alumnado de cada aula.
     */
    public const AGENDA = 'agenda';

    public static function remember(?string $centro, string $area, string $clave, int $ttl, Closure $calcular): mixed
    {
        // Sin centro (superadmin) no hay a quién aislar: no se cachea.
        if (! $centro || $ttl <= 0) {
            return $calcular();
        }

        $version = self::version($centro, $area);

        return Cache::remember("tc:{$centro}:{$area}:{$version}:{$clave}", $ttl, $calcular);
    }

    /** Invalida un área de un centro (sube su versión). */
    public static function invalidar(?string $centro, string ...$areas): void
    {
        if (! $centro || $areas === []) {
            return;
        }

        $todas = self::versiones($centro, refrescar: true);

        foreach ($areas as $area) {
            $todas[$area] = ((int) ($todas[$area] ?? 1)) + 1;
        }

        Cache::forever(self::claveVersiones($centro), $todas);
        self::memorizar($centro, $todas);
    }

    private static function version(string $centro, string $area): int
    {
        return (int) (self::versiones($centro)[$area] ?? 1);
    }

    /**
     * Versiones de todas las áreas de un centro en UNA lectura de caché. Se
     * memorizan en la petición actual (`request()->attributes`) y no en una
     * propiedad estática: con Octane una estática viviría entre peticiones y
     * dejaría de ver las invalidaciones de otros workers.
     *
     * @return array<string,int>
     */
    private static function versiones(string $centro, bool $refrescar = false): array
    {
        $atributos = request()->attributes;
        $clave = 'tenant_cache.versiones.' . $centro;

        if (! $refrescar && $atributos->has($clave)) {
            return $atributos->get($clave);
        }

        $todas = (array) Cache::get(self::claveVersiones($centro), []);
        $atributos->set($clave, $todas);

        return $todas;
    }

    private static function memorizar(string $centro, array $todas): void
    {
        request()->attributes->set('tenant_cache.versiones.' . $centro, $todas);
    }

    private static function claveVersiones(string $centro): string
    {
        return "tc:v:{$centro}";
    }
}
