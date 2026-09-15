<?php

/*
|--------------------------------------------------------------------------
| Parámetros del dominio académico
|--------------------------------------------------------------------------
|
| Estaban como constantes de clase en `StudentController` y
| `ExamAttemptRulesService`. Salen de ahí porque **describen el sistema
| educativo de un país concreto**, no una regla universal: los grados 6-12 y
| las secciones A-D son la estructura de secundaria de Costa Rica, y un centro
| con sección E o un despliegue en otro país no encajaban sin tocar código.
|
| ⚠️ Los multiplicadores de adecuación no son un parámetro de rendimiento: son
| **tiempo adicional al que un estudiante tiene derecho** por su adecuación
| curricular. Bajarlos por error reduce ese derecho de forma silenciosa, y el
| sistema no lo va a cuestionar. Cambiarlos solo con respaldo del centro.
|
*/

return [

    /*
     | Rango de grados que admite la institución. Acota la validación de
     | `students.grade` y de `groups.grade`.
     */
    'grade_min' => (int) env('ACADEMIC_GRADE_MIN', 6),
    'grade_max' => (int) env('ACADEMIC_GRADE_MAX', 12),

    /*
     | Secciones válidas. Lista separada por comas en el `.env`
     | (`ACADEMIC_SECTIONS=A,B,C,D,E`); se normaliza a mayúsculas y sin espacios.
     */
    'sections' => array_values(array_filter(array_map(
        fn ($s) => strtoupper(trim($s)),
        explode(',', (string) env('ACADEMIC_SECTIONS', 'A,B,C,D'))
    ), fn ($s) => $s !== '')),

    /*
    |--------------------------------------------------------------------------
    | Tiempo de examen
    |--------------------------------------------------------------------------
    */

    'exam' => [
        /*
         | Margen sobre el límite del intento, en segundos. Absorbe la latencia
         | del envío: sin él, una entrega entregada a tiempo pero lenta de subir
         | se rechazaría por unos milisegundos.
         */
        'grace_seconds' => (int) env('EXAM_GRACE_SECONDS', 30),

        /*
         | Multiplicadores de duración por tipo de adecuación curricular.
         | Ver el aviso de la cabecera antes de tocarlos.
         */
        'adecuacion' => [
            'acceso'     => (float) env('EXAM_ADECUACION_ACCESO', 1.25),
            'evaluacion' => (float) env('EXAM_ADECUACION_EVALUACION', 1.50),
        ],
    ],
];
