<?php

namespace App\Support;

use App\Models\Academic\Subject;
use Illuminate\Support\Collection;

/**
 * Materias de un centro por id, del catálogo en caché (`TenantCache::CATALOGO`,
 * se invalida al tocar una materia).
 *
 * Sirve para rellenar `subject` en listados y reportes sin pedir las materias a
 * la base en cada petición: con la base remota cada consulta cuesta ~0,5 s y el
 * catálogo cambia muy pocas veces. En la caché van filas crudas y los modelos se
 * arman en cada petición, para no guardar objetos Eloquent.
 */
final class CatalogoMaterias
{
    /** @return Collection<string,Subject> */
    public static function delCentro(?string $centro): Collection
    {
        if ($centro === null) {
            return collect();
        }

        $filas = TenantCache::remember(
            $centro, TenantCache::CATALOGO, 'subjects-map', 300,
            fn () => Subject::query()->toBase()->get()->map(fn ($fila) => (array) $fila)->all()
        );

        return collect($filas)->mapWithKeys(fn (array $fila) => [$fila['id'] => (new Subject())->newFromBuilder($fila)]);
    }
}
