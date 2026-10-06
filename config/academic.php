<?php

/*
|--------------------------------------------------------------------------
| Parámetros del dominio académico
|--------------------------------------------------------------------------
|
| Estaban como constantes de clase en `StudentController` y
| `ExamAttemptRulesService`. Salen de ahí porque **describen el sistema
| educativo de un país concreto**, no una regla universal: un centro con
| sección E o un despliegue en otro país no encajaban sin tocar código.
|
| ⚠️ **Corregido el 13/09/2026:** el valor por defecto era 6-12 («secundaria de
| Costa Rica»), pero el sistema es de **primaria, 1.º a 6.º**. La contradicción
| llevaba tiempo: los prompts del tutor decían «primaria» mientras la
| configuración describía secundaria, `GroupController` validaba 6-12 a mano y
| `ExamController` 7-12 — de modo que no se podía crear un examen de 6.º aunque
| sí el grupo. Ahora los tres salen de aquí.
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
    'grade_min' => (int) env('ACADEMIC_GRADE_MIN', 1),
    'grade_max' => (int) env('ACADEMIC_GRADE_MAX', 6),

    /*
     | Cómo se le describe la etapa al modelo en los prompts del tutor.
     |
     | Estaba escrita a mano —«un estudiante de primaria»— en los **tres**
     | prompts (chat, diagnóstico y recomendaciones), que es exactamente la
     | misma trampa que tenían los rangos de grado: tres copias que nadie
     | actualiza a la vez. Un centro que despliegue esto para secundaria cambia
     | esta línea y el rango de arriba, y no toca código.
     |
     | La edad importa y por eso va dentro: al modelo le dice más «6 a 12 años»
     | que «primaria», que es una etiqueta que cambia de país a país.
     */
    'etapa' => env('ACADEMIC_STAGE_LABEL', 'primaria (6 a 12 años)'),

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
         | Tope de pausa ACUMULADA por intento, en segundos (15 min por defecto).
         |
         | La pausa existe para una desconexión o una urgencia, no para ganar
         | tiempo: sin tope, cada segundo pausado se sumaba al plazo, así que una
         | pausa de dos horas daba dos horas más de examen —y con él abierto en
         | otra pestaña—. Pasado el tope, la pausa deja de acreditarse y el plazo
         | no se alarga más. `0` desactiva las pausas por completo.
         */
        'max_pause_seconds' => (int) env('EXAM_MAX_PAUSE_SECONDS', 900),

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
