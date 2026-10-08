<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ¿Sigue vivo este enlace? Se consulta antes de que el tutor se lo entregue a
 * un alumno, para no mandar a un niño de primaria a una página que ya no existe
 * o a un vídeo borrado.
 *
 * ## Qué cuenta como roto
 *
 * - **Roto**: 404 o 410, el vídeo de YouTube que ya no existe o es privado
 *   (su `oembed` responde 404/401), un host fuera de la lista blanca, o una
 *   redirección que acaba fuera de ella.
 * - **Vivo**: 2xx/3xx.
 * - **Sin veredicto** (tiempo agotado, sin red, 5xx, 403/429 porque el sitio
 *   rechaza el sondeo): se da por vivo. Una caída pasajera de YouTube no debe
 *   dejar a todo el alumnado sin recursos, y un 403 de antibots no prueba que
 *   el enlace esté roto. Ese resultado se guarda poco tiempo para reintentar.
 *
 * El veredicto se cachea (`ai_resources.link_check`): el mismo vídeo lo ve
 * mucho alumnado y no hay por qué preguntar cada vez. Para YouTube se usa
 * `oembed`, que distingue vídeos borrados o privados; un `HEAD` a la página de
 * YouTube devuelve 200 aunque el vídeo no exista.
 *
 * El destino solo puede ser un dominio de `ai_resources.allowed_domains`, y las
 * redirecciones se revalidan contra esa misma lista: el sondeo no puede
 * usarse para hacer que el servidor consulte direcciones internas.
 */
class EnlaceDisponible
{
    public function disponible(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        // Apagado (tests, entornos sin salida a internet): no se juzga nada.
        if (!config('ai_resources.link_check.enabled')) {
            return true;
        }

        $host = $this->hostPermitido($url);

        if ($host === null) {
            return false;
        }

        $clave = 'ai:link:' . sha1($url);

        $guardado = Cache::get($clave);
        if ($guardado !== null) {
            return $guardado === 'ok';
        }

        $veredicto = $this->sondear($url, $host);

        Cache::put(
            $clave,
            $veredicto === false ? 'roto' : 'ok',
            (int) config('ai_resources.link_check.' . match ($veredicto) {
                true    => 'ttl_ok',
                false   => 'ttl_broken',
                default => 'ttl_unknown',
            })
        );

        return $veredicto !== false;
    }

    /** true = vivo, false = roto, null = sin veredicto. */
    private function sondear(string $url, string $host): ?bool
    {
        $cliente = Http::timeout((int) config('ai_resources.link_check.timeout'))
            ->withHeaders(['User-Agent' => 'NeoEduCore-LinkCheck/1.0'])
            ->withOptions([
                'allow_redirects' => [
                    'max'         => 3,
                    'protocols'   => ['http', 'https'],
                    'on_redirect' => function ($request, $response, $uri) {
                        if ($this->hostPermitido((string) $uri) === null) {
                            throw new \RuntimeException('Redirección fuera de la lista blanca');
                        }
                    },
                ],
            ]);

        try {
            if ($this->esYoutube($host)) {
                $respuesta = $cliente->get('https://www.youtube.com/oembed', ['url' => $url, 'format' => 'json']);

                return $this->veredicto($respuesta->status(), [401, 403, 404]);
            }

            $respuesta = $cliente->head($url);

            // Algunos sitios no admiten HEAD.
            if (in_array($respuesta->status(), [403, 405, 501], true)) {
                $respuesta = $cliente->get($url);
            }

            return $this->veredicto($respuesta->status(), [404, 410]);
        } catch (ConnectionException) {
            return null;
        } catch (\RuntimeException $e) {
            // La redirección acabó fuera de la lista blanca.
            return str_contains($e->getMessage(), 'lista blanca') ? false : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int,int> $rotos */
    private function veredicto(int $status, array $rotos): ?bool
    {
        if (in_array($status, $rotos, true)) {
            return false;
        }

        return $status >= 200 && $status < 400 ? true : null;
    }

    private function esYoutube(string $host): bool
    {
        foreach (['youtube.com', 'youtu.be'] as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                return true;
            }
        }

        return false;
    }

    /** El host si la URL es http(s) y de un dominio permitido; null si no. */
    private function hostPermitido(string $url): ?string
    {
        $partes = parse_url($url);
        $host   = strtolower($partes['host'] ?? '');

        if (!in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true) || $host === '') {
            return null;
        }

        foreach ((array) config('ai_resources.allowed_domains', []) as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                return $host;
            }
        }

        return null;
    }
}
