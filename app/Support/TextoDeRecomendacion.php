<?php

namespace App\Support;

/**
 * Limpia el texto de una recomendación de IA de los bloques JSON que el modelo añade para el sistema.
 *
 * El modelo cierra el apartado del recurso con algo como
 *
 *     Puedes usar «Khan Academy»…
 *     ```json
 *     { "resources": [ { "name": "Khan Academy", "link": "https://…" } ] }
 *     ```
 *
 * y ese bloque es para el sistema (`ai_recommendations.resource`), no para quien lee: salía en la pantalla
 * del docente y del alumno con sus llaves, sus comillas y hasta el enlace que la lista blanca había bloqueado.
 *
 * No se usa una expresión regular de `{…}` porque los bloques anidan (`{ "a": [ { … } ] }`): se cuentan las
 * llaves, respetando las comillas, y solo es bloque lo que de verdad es JSON.
 */
final class TextoDeRecomendacion
{
    /**
     * Los bloques JSON (objetos) que contiene el texto, en orden.
     *
     * @return array<int,array{inicio:int,largo:int,datos:array<mixed>}>
     */
    public static function bloquesJson(string $texto): array
    {
        $bloques = [];
        $n = strlen($texto);

        for ($i = 0; $i < $n; $i++) {
            if ($texto[$i] !== '{') {
                continue;
            }

            $fin = self::cierre($texto, $i);

            if ($fin === null) {
                continue;
            }

            $datos = json_decode(substr($texto, $i, $fin - $i + 1), true);

            if (is_array($datos)) {
                $bloques[] = ['inicio' => $i, 'largo' => $fin - $i + 1, 'datos' => $datos];
                $i = $fin;
            }
        }

        return $bloques;
    }

    /** El texto sin los bloques JSON ni las marcas de código (```json, `json) que los envolvían. */
    public static function sinBloquesJson(string $texto): string
    {
        foreach (array_reverse(self::bloquesJson($texto)) as $bloque) {
            $antes = preg_replace('/`{1,3}\s*(?:json)?\s*$/i', '', substr($texto, 0, $bloque['inicio'])) ?? '';
            $despues = preg_replace('/^\s*`{1,3}/', '', substr($texto, $bloque['inicio'] + $bloque['largo'])) ?? '';
            $texto = $antes . $despues;
        }

        // Marcas sueltas que quedaron (una línea que es solo ``` o ```json).
        $texto = preg_replace('/^[ \t]*`{1,3}(?:json)?[ \t]*$/im', '', $texto) ?? $texto;
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto;

        return trim($texto);
    }

    /** Posición de la llave que cierra la de `$desde`, o null si no cierra. Las llaves dentro de comillas no cuentan. */
    private static function cierre(string $texto, int $desde): ?int
    {
        $profundidad = 0;
        $enCadena = false;
        $n = strlen($texto);

        for ($i = $desde; $i < $n; $i++) {
            $c = $texto[$i];

            if ($enCadena) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $enCadena = false;
                }

                continue;
            }

            if ($c === '"') {
                $enCadena = true;
            } elseif ($c === '{') {
                $profundidad++;
            } elseif ($c === '}') {
                $profundidad--;

                if ($profundidad === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
