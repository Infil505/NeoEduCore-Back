<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de un enlace de apoyo del examen (`exams.support_resources.*.url`).
 *
 * Mismo criterio de host que la lista blanca general (sufijo tras un punto, así
 * `youtube.com` admite `www.` pero no `youtube.com.evil.net`), y solo http(s).
 * Si el elemento es de tipo `video` se exige además que sea de una plataforma de
 * vídeo (`UrlDeVideo`), porque el frontend lo incrusta como reproductor.
 */
class EnlaceDeApoyo implements ValidationRule, DataAwareRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $partes  = is_string($value) ? parse_url($value) : false;
        $esquema = strtolower($partes['scheme'] ?? '');
        $host    = strtolower($partes['host'] ?? '');

        if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
            $fail('Cada enlace debe ser una dirección http(s) válida.');
            return;
        }

        // `support_resources.2.url` → el tipo vive en `support_resources.2.type`.
        $tipo = data_get($this->data, preg_replace('/\.url$/', '.type', $attribute));

        if ($tipo === 'video') {
            (new UrlDeVideo())->validate($attribute, $value, $fail);
            return;
        }

        foreach ((array) config('ai_resources.allowed_domains', []) as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                return;
            }
        }

        $fail('El enlace debe ser de un sitio educativo permitido.');
    }
}
