<?php

namespace App\Services\AI;

class AiOutputValidator
{
    private const MAX_LENGTH = 4000;
    private const MIN_LENGTH = 5;

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

        if (strlen($text) < self::MIN_LENGTH) {
            return 'La respuesta del modelo es demasiado corta.';
        }

        if (strlen($text) > self::MAX_LENGTH) {
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
        $text = trim($text);

        if (strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        return $text;
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
