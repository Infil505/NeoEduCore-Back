<?php

namespace App\Services\AI;

class AiOutputValidator
{
    private const MAX_LENGTH = 4000;
    private const MIN_LENGTH = 5;

    // Patrones que indican posible dato personal
    private const PATTERNS_PII = [
        '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',  // email
        '/\b(?:\+?[0-9]{7,15})\b/',                           // teléfono
        '/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/',             // fecha numérica (posible DNI/fecha)
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
