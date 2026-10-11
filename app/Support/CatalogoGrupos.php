<?php

namespace App\Support;

use App\Models\Academic\Group;
use Illuminate\Support\Collection;

/**
 * Aulas de un centro por id, del catálogo en caché (`TenantCache::MAPAS`; se invalida al tocar un
 * aula y al cambiar matrículas, porque la fila lleva `student_count`). Mismo criterio que
 * `CatalogoMaterias`: sirve para comprobar que un aula existe o para devolverla en una respuesta sin
 * pedirla a la base en cada escritura.
 */
final class CatalogoGrupos
{
    /** @return Collection<string,Group> */
    public static function delCentro(?string $centro): Collection
    {
        if ($centro === null) {
            return collect();
        }

        $filas = TenantCache::remember(
            $centro, TenantCache::MAPAS, 'groups-map', 300,
            fn () => Group::query()->toBase()->get()->map(fn ($fila) => (array) $fila)->all()
        );

        return collect($filas)->mapWithKeys(fn (array $fila) => [$fila['id'] => (new Group())->newFromBuilder($fila)]);
    }
}
