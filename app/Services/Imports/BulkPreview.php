<?php

namespace App\Services\Imports;

/**
 * Vista previa de una carga masiva (`dry_run`).
 *
 * Se ejecuta el mismo recorrido de validación que la carga real y se omite la
 * escritura. (La carga de docentes sigue ejecutándose entera y deshaciéndose
 * con `revertir()`; la de estudiantes ya valida contra mapas en memoria y
 * escribe en bloque al final, así que la simulación no abre transacción.) Así
 * la vista previa marca exactamente los errores que daría la carga real (aula
 * que no existe, correo ya usado, correo repetido en el propio archivo…) sin
 * una segunda copia de las reglas que pudiera desincronizarse. No se envía
 * ningún correo: eso ocurre después del commit.
 */
class BulkPreview
{
    /** Excepción que deshace la transacción de la simulación. */
    public static function revertir(): never
    {
        throw new SimulacionRevertida();
    }

    /**
     * Una fila por registro del archivo, con su resultado.
     *
     * @param array<int,array<string,mixed>> $rows      registros por número de fila
     * @param array<int,string>              $errors    mensajes «Fila N: …» de la carga
     * @param array<int,string>              $acciones  número de fila → 'crear' | 'actualizar'
     * @param array<int,string>              $columnas  columnas que se muestran
     */
    public static function respuesta(array $rows, array $errors, array $acciones, array $columnas): array
    {
        $erroresPorFila = [];
        foreach ($errors as $mensaje) {
            if (preg_match('/^Fila (\d+): (.*)$/su', $mensaje, $m)) {
                $erroresPorFila[(int) $m[1]][] = $m[2];
            }
        }

        $filas = [];
        foreach ($rows as $linea => $row) {
            $filas[] = [
                'line'   => $linea,
                'data'   => array_map(
                    fn ($v) => $v === null ? '' : trim((string) $v),
                    array_intersect_key($row, array_flip($columnas)) + array_fill_keys($columnas, '')
                ),
                'action' => $acciones[$linea] ?? null,
                'errors' => $erroresPorFila[$linea] ?? [],
            ];
        }

        $conError = count(array_filter($filas, fn ($f) => $f['errors'] !== []));

        return [
            'dry_run'    => true,
            'total_rows' => count($filas),
            'valid'      => count($filas) - $conError,
            'invalid'    => $conError,
            'rows'       => $filas,
        ];
    }
}
