<?php

namespace App\Support;

use App\Models\Admin\Institution;

/**
 * Ajustes del centro (`institutions.settings`) ya mezclados con los valores por defecto, leídos de
 * la fila del centro en caché (`TenantCache::CONFIG`, se invalida al guardar la institución).
 *
 * Antes cada validación que necesitaba un ajuste (duración máxima del examen, nota mínima…) pedía
 * la fila a la base: con la base remota, ~0,4 s por petición.
 */
final class AjustesDelCentro
{
    /** @return array<string,mixed> */
    public static function de(?string $centro): array
    {
        if ($centro === null) {
            return Institution::$defaultSettings;
        }

        $fila = TenantCache::remember(
            $centro, TenantCache::CONFIG, 'institution-row', 600,
            fn () => Institution::query()->whereKey($centro)->toBase()->first()
        );

        $guardados = $fila?->settings ?? [];
        if (is_string($guardados)) {
            $guardados = json_decode($guardados, true) ?: [];
        }

        return array_merge(Institution::$defaultSettings, (array) $guardados);
    }
}
