<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de un vídeo de `config('ai_resources.video_domains')` (hoy, YouTube).
 *
 * Por sufijo de host, como la lista blanca general: `youtube.com` admite
 * `www.youtube.com` y `m.youtube.com`, pero no `youtube.com.evil.net`, porque
 * se compara el final exacto tras un punto.
 */
class UrlDeVideo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $partes = is_string($value) ? parse_url($value) : false;
        $esquema = strtolower($partes['scheme'] ?? '');
        $host    = strtolower($partes['host'] ?? '');

        if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
            $fail('El vídeo debe ser un enlace http(s) válido.');
            return;
        }

        foreach ((array) config('ai_resources.video_domains', []) as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                return;
            }
        }

        $fail('El vídeo debe ser de YouTube.');
    }
}
