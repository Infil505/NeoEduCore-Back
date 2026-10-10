<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Trae las relaciones «a uno» de un listado EN LA MISMA consulta (LEFT JOIN) en
 * lugar de una consulta por relación (`with()`).
 *
 * Un listado con `with(['creator', 'group', 'exam'])` son CUATRO viajes a la base
 * (la tabla y una por relación) aunque devuelva cinco filas. Con la base remota
 * (~0,4 s por viaje) son 1,6 s; con este ayudante, uno solo. En el JSON no cambia
 * nada: cada fila sigue llevando `creator: {id, full_name}`, `group: {…}`, etc.
 *
 * Uso:
 *
 *   $q = RelacionesEnLinea::unir(CalendarEvent::query(), [
 *       'creator' => ['users',  'created_by', ['id', 'full_name'], User::class],
 *       'group'   => ['groups', 'group_id',   ['id', 'name', 'grade', 'section'], Group::class],
 *   ]);
 *   $filas = $q->get();
 *   RelacionesEnLinea::hidratar($filas, ['creator' => User::class, 'group' => Group::class]);
 *
 * El `JOIN` exige que la fila relacionada sea del MISMO centro (`institution_id`),
 * igual que el alcance por institución de las consultas normales: nunca une con una
 * fila de otro centro.
 */
final class RelacionesEnLinea
{
    private const SEPARADOR = '__';

    /**
     * @param  array<string,array{0:string,1:string,2:array<int,string>,3:class-string<Model>}>  $relaciones
     *         nombre => [tabla, columna clave foránea en la tabla base, columnas a traer, clase del modelo]
     */
    public static function unir(Builder $consulta, array $relaciones): Builder
    {
        $base = $consulta->getModel()->getTable();
        $consulta->select("{$base}.*");

        foreach ($relaciones as $nombre => [$tabla, $clave, $columnas]) {
            // El alias es el nombre de la relación: no choca con ninguna tabla real.
            $consulta->leftJoin("{$tabla} as {$nombre}", function ($join) use ($nombre, $base, $clave) {
                $join->on("{$nombre}.id", '=', "{$base}.{$clave}")
                     ->on("{$nombre}.institution_id", '=', "{$base}.institution_id");
            });

            foreach ($columnas as $columna) {
                $consulta->addSelect("{$nombre}.{$columna} as {$nombre}" . self::SEPARADOR . $columna);
            }
        }

        return $consulta;
    }

    /**
     * Convierte las columnas `{relación}__{campo}` de cada fila en la relación
     * cargada (`setRelation`) y las quita de los atributos, de modo que el JSON es
     * el mismo que con `with()`.
     *
     * @param  iterable<Model>  $modelos
     * @param  array<string,class-string<Model>>  $relaciones  nombre => clase
     */
    public static function hidratar(iterable $modelos, array $relaciones): void
    {
        foreach ($modelos as $modelo) {
            $atributos = $modelo->getAttributes();
            $propios = $atributos;

            foreach ($relaciones as $nombre => $clase) {
                $prefijo = $nombre . self::SEPARADOR;
                $datos = [];

                foreach ($atributos as $clave => $valor) {
                    if (str_starts_with($clave, $prefijo)) {
                        $datos[substr($clave, strlen($prefijo))] = $valor;
                        unset($propios[$clave]);
                    }
                }

                // LEFT JOIN sin fila relacionada: todas las columnas llegan nulas.
                $modelo->setRelation(
                    $nombre,
                    ($datos['id'] ?? null) === null ? null : (new $clase())->newFromBuilder($datos)
                );
            }

            $modelo->setRawAttributes(Arr::only($propios, array_keys($propios)), true);
        }
    }
}
