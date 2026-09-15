<?php

namespace App\Services\AI;

class AiOutputValidator
{
    /**
     * Umbrales de longitud aceptable de una respuesta del tutor.
     *
     * Por defecto salen de `config/openai.php` (sección `output`), pero se
     * pueden inyectar: esta clase no depende de nada del framework y sus
     * pruebas son unitarias de verdad —sin base de datos ni contenedor—, así
     * que leer `config()` a ciegas las rompería. Inyectarlos también deja los
     * valores a la vista en el test, que es lo que allí se está comprobando.
     */
    public function __construct(
        private ?int $minLength = null,
        private ?int $maxLength = null,
    ) {
    }

    private function limite(string $clave): int
    {
        $inyectado = $clave === 'min_length' ? $this->minLength : $this->maxLength;

        return $inyectado ?? (int) config("openai.output.{$clave}");
    }

    /** Lo que queda en el texto donde había un enlace fuera de la lista blanca. */
    public const URL_BLOQUEADA = '[enlace no permitido]';

    // Patrones que indican posible dato personal.
    // Nota: se afinan para NO marcar números "normales" de un tutor (resultados
    // de matemáticas, rangos de años, fechas de una explicación). Solo se bloquea
    // lo que realmente parece un contacto: email o teléfono/identificador.
    private const PATTERNS_PII = [
        // Email
        '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
        // Teléfono internacional con prefijo + (ej: +57 3101234567)
        '/\+\d{1,3}[\s.\-]?\d{6,14}/',
        // Teléfono con separadores en 3 grupos (ej: 310-123-4567, 601 234 5678)
        '/\b\d{2,4}[\s.\-]\d{3,4}[\s.\-]\d{2,4}\b/',
        // Secuencia larga de dígitos sin formato (posible teléfono/DNI/documento)
        '/\b\d{10,}\b/',
    ];

    public function validate(string $text): ?string
    {
        $text = trim($text);

        if (strlen($text) < $this->limite('min_length')) {
            return 'La respuesta del modelo es demasiado corta.';
        }

        if (strlen($text) > $this->limite('max_length')) {
            return 'La respuesta del modelo excede el tamaño máximo permitido.';
        }

        foreach (self::PATTERNS_PII as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'La respuesta del modelo contiene posibles datos personales y fue bloqueada.';
            }
        }

        return null; // null = válido
    }

    public function sanitize(string $text): string
    {
        $text = $this->sanitizeUrls(trim($text));

        if (strlen($text) > $this->limite('max_length')) {
            $text = mb_substr($text, 0, $this->limite('max_length'));
        }

        return $text;
    }

    /**
     * Sustituye por un aviso las URLs que no estén en la lista blanca.
     *
     * `isUrlAllowed()` solo se aplicaba a la URL del JSON estructurado que el
     * modelo emite al regenerar recomendaciones, y a las que teclea un docente en
     * `study_resources`. El **texto libre no pasaba por ningún filtro**: si el
     * tutor escribía un enlace en medio de una explicación, llegaba tal cual a un
     * niño de primaria. [173] no distingue canales: los materiales externos van
     * «mediante una lista blanca de recursos educativos verificados».
     *
     * Se sustituye el enlace en vez de bloquear la respuesta entera: la
     * explicación sigue siendo útil sin él, y descartarla haría que el tutor
     * pareciera averiado cada vez que el modelo cita una fuente cualquiera.
     */
    public function sanitizeUrls(string $text): string
    {
        // Los cierres habituales de markdown y de puntuación no son parte del
        // host, pero sí los captura un `\S+`, así que se excluyen del match.
        return (string) preg_replace_callback(
            '#\bhttps?://[^\s<>"\'\)\]]+#i',
            fn (array $m) => $this->isUrlAllowed($m[0]) ? $m[0] : self::URL_BLOQUEADA,
            $text
        );
    }

    public function isUrlAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === false || $host === null) {
            return false;
        }

        $host = strtolower($host);
        $allowed = config('ai_resources.allowed_domains', []);

        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
