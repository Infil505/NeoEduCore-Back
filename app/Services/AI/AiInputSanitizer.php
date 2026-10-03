<?php

namespace App\Services\AI;

/**
 * El lado de la entrada, simétrico a `AiOutputValidator`.
 *
 * Aquel filtra lo que el modelo devuelve; este filtra **lo que entra en el
 * prompt**. Hacían falta los dos: hasta ahora todo lo que un alumno o un
 * docente escribía —el mensaje del chat, el `topic` del modo, el enunciado de
 * una pregunta, la respuesta libre de un examen— se concatenaba tal cual en el
 * texto que iba a OpenAI, mezclado con las instrucciones del sistema y sin nada
 * que distinguiera una cosa de la otra.
 *
 * ## Dos defensas, no una
 *
 * - `paraPrompt()` **neutraliza la forma**: quita lo que sirve para fingir
 *   estructura (corchetes de los tokens de modo, saltos de línea que simulan
 *   una sección nueva, caracteres de control) y acota la longitud. Se aplica a
 *   todo dato de usuario que entre en un prompt, venga de quien venga.
 * - `pareceInyeccion()` **detecta la intención** en el texto libre del alumno.
 *   Lo que casa no se envía a OpenAI: se bloquea, se registra como incidencia
 *   (D5) y el alumno recibe una negativa amable.
 *
 * ## Por qué los patrones son estrechos
 *
 * Quien escribe aquí tiene entre 6 y 12 años. «No entiendo las instrucciones
 * del ejercicio, ignóralas» es una frase perfectamente normal en primaria, y
 * bloquearla sería peor que el ataque: el crío se queda sin tutor y nadie sabe
 * por qué. Por eso `ignorar/olvidar` **solo** cuenta cuando el objeto señala a
 * las instrucciones del propio tutor («tus reglas», «las instrucciones
 * anteriores», «el prompt del sistema»), nunca a secas.
 *
 * La contrapartida es asumida: un ataque redactado con cuidado pasa el filtro.
 * Esta clase es la segunda línea; la primera son las reglas del system prompt,
 * que tratan el mensaje del alumno como dato y no como instrucción.
 */
class AiInputSanitizer
{
    /** Tope por defecto de un dato suelto dentro de un prompt. */
    public const MAX_CAMPO = 240;

    /**
     * Lo que se arranca del texto antes de que entre en un prompt.
     *
     * Los corchetes se van porque son el envoltorio de los tokens de modo
     * (`[MODO: práctica '…']`): sin ellos, lo que escriba el alumno no puede
     * pasar por uno. Las llaves, por el JSON de la sección `resource` de las
     * recomendaciones. Y `<|…|>` porque es el delimitador de turno de los
     * modelos de OpenAI.
     */
    private const PATRON_ESTRUCTURA = '/[\[\]\{\}]|<\|[^|]*\|>/u';

    /** Caracteres de control salvo el tabulador, que es inocuo. */
    private const PATRON_CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /**
     * Intentos de reescribir las instrucciones del tutor.
     *
     * Dos familias. La primera es **estructural** y casi no tiene falsos
     * positivos: nadie de primaria escribe `<|im_start|>` ni `"role": "system"`
     * sin querer. La segunda es **semántica** y va deliberadamente ceñida, por
     * lo dicho en el docblock de la clase.
     */
    private const PATRONES_INYECCION = [
        // --- Estructurales: fingir que se es el sistema ---
        '/<\|\s*im_(start|end)\s*\|>/i',
        '/["\']?\brole\b["\']?\s*[:=]\s*["\']?\s*system\b/i',
        '/^\s*#{2,}\s*(system|sistema|instrucciones)\b/im',
        '/\[\s*(modo|mode|system|sistema)\s*[:\]]/i',

        /*
         | --- Semánticos: pedir que se anulen las reglas ---
         |
         | Un verbo de anular no basta, y un sustantivo tampoco: hacen falta los
         | dos **y** algo que señale a las instrucciones del propio tutor. Por
         | eso hay dos variantes de la misma idea, según dónde caiga ese algo:
         |
         |   «olvida TUS reglas»                  → calificativo antes
         |   «ignora las instrucciones ANTERIORES» → calificativo después
         |
         | Sin esa tercera pieza, «ignora las instrucciones del ejercicio» —una
         | frase de deberes perfectamente normal— caería en el filtro.
         */
        '/\b(ign[oó]r\w*|olv[ií]d\w*|descart\w*|s[aá]lt\w*)\b[^.\n]{0,40}\b(tus|sus|del\s+sistema)\b[^.\n]{0,25}\b(instruccion\w*|regla\w*|indicacion\w*|[oó]rden\w*|directri\w*|prompt)\b/iu',
        '/\b(ign[oó]r\w*|olv[ií]d\w*|descart\w*|s[aá]lt\w*)\b[^.\n]{0,40}\b(instruccion\w*|regla\w*|indicacion\w*|[oó]rden\w*|directri\w*|prompt)\b[^.\n]{0,25}\b(anterior\w*|previa\w*|inicial\w*|de\s+arriba|del\s+sistema|tuya\w*)\b/iu',
        '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|above|prior|earlier|your|all)\b[^.\n]{0,25}\b(instruction|rule|prompt|direction)/i',

        /*
         | Pedir el prompt del sistema. El calificativo se limita a `tus|sus|del
         | sistema`: con un `las` genérico, «dime las reglas de los acentos»
         | quedaba bloqueado.
         */
        '/\b(mu[eé]str\w*|revel\w*|rep[ií]t\w*|d[ií]me|decime|imprim\w*)\b[^.\n]{0,40}\b(tus|sus|del\s+sistema)\b[^.\n]{0,25}\b(instruccion\w*|regla\w*|prompt)\b/iu',
        '/\b(reveal|show|print|repeat)\b[^.\n]{0,30}\b(your|the)\b[^.\n]{0,20}\b(system\s+prompt|instructions|rules)/i',
        '/\bprompt\s+del\s+sistema\b/iu',
        '/\bsystem\s+prompt\b/i',

        // Reasignación de identidad y modos «sin filtro».
        '/\b(a\s+partir\s+de\s+ahora|desde\s+ahora|ahora)\s+(eres|actu[aá]s?|te\s+comportas)\b/iu',
        '/\b(you\s+are\s+now|from\s+now\s+on\s+you)\b/i',
        '/\b(finge|pretende|simula)\s+(ser|que\s+eres)\b/iu',
        '/\b(pretend|act)\s+(to\s+be|as\s+if\s+you)\b/i',
        '/\bmodo\s+(desarrollador|dios|sin\s+filtros?|sin\s+restricciones)\b/iu',
        '/\b(developer|god|jailbreak|DAN)\s+mode\b/i',
        // «sin reglas» se queda fuera a propósito: un juego sin reglas es una
        // consulta normal. Las otras tres no son vocabulario de primaria en
        // esta construcción.
        '/\bsin\s+(ninguna\s+)?(restricci[oó]n|restricciones|filtro|filtros|censura|l[ií]mite|l[ií]mites)\b/iu',
    ];

    /**
     * Deja un texto de usuario en condiciones de entrar en un prompt.
     *
     * Devuelve cadena vacía para null o para lo que quede vacío tras limpiar,
     * de modo que quien llama pueda decidir con un `!== ''` si esa parte del
     * prompt se escribe o se omite.
     */
    public function paraPrompt(?string $texto, int $max = self::MAX_CAMPO): string
    {
        if ($texto === null) {
            return '';
        }

        $texto = preg_replace(self::PATRON_CONTROL, '', $texto) ?? '';
        $texto = preg_replace(self::PATRON_ESTRUCTURA, '', $texto) ?? '';

        // Los saltos de línea pasan a espacio: un dato de una línea no puede
        // abrir lo que parezca una sección nueva del prompt.
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';

        return mb_substr(trim($texto), 0, $max);
    }

    /**
     * ¿Este texto libre intenta reescribir las instrucciones del tutor?
     */
    public function pareceInyeccion(?string $texto): bool
    {
        if ($texto === null || trim($texto) === '') {
            return false;
        }

        foreach (self::PATRONES_INYECCION as $patron) {
            if (preg_match($patron, $texto) === 1) {
                return true;
            }
        }

        return false;
    }
}
