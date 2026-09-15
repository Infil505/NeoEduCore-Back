<?php

namespace App\Services\AI;

/**
 * Cómo debe escribirle el tutor a un estudiante según su grado.
 *
 * Entre 1.º y 6.º de primaria hay seis años de diferencia lectora. El sistema
 * mandaba al modelo el grado como número suelto («grado 3») y una instrucción
 * genérica, así que el registro lo decidía él: un alumno de 1.º podía recibir
 * el mismo párrafo que uno de 6.º. A esa edad eso no es un matiz de estilo —
 * muchos apenas leen con fluidez, y un texto denso no llega a leerse.
 *
 * Vive aparte de los servicios porque lo usan los **tres** prompts —chat,
 * diagnóstico y recomendaciones post-examen— y antes solo el chat sabía
 * siquiera el grado. Las franjas están en `config/openai.php`, no aquí: es
 * texto pedagógico y lo afina el profesorado, no el código.
 */
class RegistroPorGrado
{
    /**
     * Instrucción de registro para ese grado, lista para concatenar al prompt.
     *
     * Sin grado conocido —el alumnado cargado en masa suele no traerlo— se
     * devuelve el texto conservador: sencillo, sin dar por supuesta una lectura
     * rápida. Es preferible quedarse corto que escribirle a un niño de 7 años
     * como a uno de 12.
     */
    public function para(?int $grado): string
    {
        if ($grado === null) {
            return (string) config('openai.tutor.registro.sin_grado');
        }

        foreach ((array) config('openai.tutor.registro.franjas', []) as $franja) {
            if ($grado <= (int) ($franja['hasta'] ?? 0)) {
                return (string) ($franja['texto'] ?? '');
            }
        }

        // Por encima de la última franja: se aplica esa misma. Pasa si algún día
        // el centro amplía el rango de grados y nadie añade la franja nueva —
        // mejor el registro más alto que ninguno.
        $franjas = (array) config('openai.tutor.registro.franjas', []);
        $ultima  = end($franjas);

        return (string) ($ultima['texto'] ?? config('openai.tutor.registro.sin_grado'));
    }
}
