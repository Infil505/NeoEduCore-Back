<?php

namespace App\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Compara dos volcados de `schema:dump-sql` ignorando lo que varía sin que el
 * esquema cambie. Lo usa `schema:check-drift`.
 *
 * Lo único que se descarta son las dos cabeceras de versión de `pg_dump`
 * (servidor y cliente) y los finales de línea: el artefacto sale de Supabase y
 * la comparación de una BD local, y esas líneas difieren siempre. Cualquier
 * otra diferencia es drift.
 */
class SchemaDrift
{
    private const LINEAS_IGNORADAS = [
        '-- Dumped from database version',
        '-- Dumped by pg_dump version',
    ];

    public static function normalize(string $sql): string
    {
        $lineas = explode("\n", str_replace("\r\n", "\n", $sql));

        $lineas = array_filter($lineas, function (string $linea) {
            foreach (self::LINEAS_IGNORADAS as $prefijo) {
                if (str_starts_with($linea, $prefijo)) {
                    return false;
                }
            }
            return true;
        });

        return rtrim(implode("\n", $lineas)) . "\n";
    }

    /**
     * Diff unificado de `$esperado` (el artefacto) a `$generado` (lo que dejan
     * las migraciones), o null si no hay drift.
     */
    public static function diff(string $esperado, string $generado): ?string
    {
        $a = self::normalize($esperado);
        $b = self::normalize($generado);

        if ($a === $b) {
            return null;
        }

        // sebastian/diff llega con phpunit (require-dev): en una imagen
        // instalada con --no-dev no está, y el comando no debe reventar ahí.
        if (! class_exists(Differ::class)) {
            return self::diffSinLibreria($a, $b);
        }

        $builder = new UnifiedDiffOutputBuilder("--- 01_schema.sql (artefacto)\n+++ migraciones\n");

        return (new Differ($builder))->diff($a, $b);
    }

    /** Líneas que solo están en un lado. Sin contexto, pero suficiente para ver el drift. */
    private static function diffSinLibreria(string $a, string $b): string
    {
        $la = explode("\n", $a);
        $lb = explode("\n", $b);

        $salida = [];
        foreach (array_diff($la, $lb) as $linea) {
            $salida[] = "- {$linea}";
        }
        foreach (array_diff($lb, $la) as $linea) {
            $salida[] = "+ {$linea}";
        }

        // Mismas líneas en otro orden: array_diff no lo ve, pero sigue siendo drift.
        return $salida === []
            ? "(mismas líneas en distinto orden)\n"
            : implode("\n", $salida) . "\n";
    }
}
