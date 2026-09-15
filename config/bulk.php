<?php

/*
|--------------------------------------------------------------------------
| Operaciones por lotes
|--------------------------------------------------------------------------
|
| Límites de recursos, no reglas de negocio: acotan cuánta memoria y cuánto
| tiempo de worker puede consumir una sola petición. Estaban incrustados en
| `StudentController` y `ExamGradingService`.
|
| Subir `max_rows` o `max_mb` no es gratis: la carga masiva procesa el archivo
| entero dentro de una transacción, así que el coste es memoria del worker y
| duración del bloqueo. Con Octane los workers son ~40 en total.
|
*/

return [

    /*
     | Carga masiva de estudiantes (`POST /api/students/bulk-upload`).
     */
    'students' => [
        'max_rows' => (int) env('BULK_MAX_ROWS', 5000),
        'max_mb'   => (int) env('BULK_MAX_MB', 5),
    ],

    /*
     | Tamaño de lote de los INSERT agrupados al calificar un intento.
     | Es puro rendimiento: lotes grandes bajan el número de viajes a la base,
     | pero cada uno arma una sentencia más larga y ocupa más memoria.
     */
    'insert_batch_size' => (int) env('BULK_INSERT_BATCH_SIZE', 500),
];
