<?php

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cuántas consultas puede hacerle un estudiante al tutor IA en un día.
 *
 * Con cientos de estudiantes cada mensaje es una llamada a OpenAI (dinero y un worker ocupado), así que el
 * tope es POR PERSONA y POR DÍA (`openai.tutor.daily_limit`, 5 por defecto). El día es el del colegio
 * (`openai.tutor.daily_timezone`, Costa Rica), no el de UTC: con UTC la cuota se renovaría a las 6 de la tarde.
 *
 * - **Atómico.** `reservar()` suma y comprueba en una sola operación de la caché: dos mensajes simultáneos no
 *   pueden colarse por encima del tope. Si no cabía, deshace su suma.
 * - **Solo cuenta lo que cuesta.** Quien llama `devuelve()` la consulta si no llegó al modelo (mensaje rechazado,
 *   intento de inyección, respuesta de reserva porque OpenAI falló): quedarse sin cupo por una caída ajena no es justo.
 * - **Vive en la caché.** La clave caduca sola al terminar el día. Con `CACHE_STORE=file` vale para un solo
 *   servidor; con varios procesos o servidores hace falta una caché compartida (redis), como con los demás límites.
 *
 * `daily_limit <= 0` apaga la cuota (sin límite).
 *
 * **Dos bolsas, la misma mecánica.** `tutor` (por defecto): las consultas del estudiante al chat. `docente`
 * (`delPersonal()`): todo lo que el PERSONAL (docente o administrador) pide a la IA —asistente, consejos, planes y
 * «Redactar con IA» del análisis—, una sola cuenta compartida por persona (`openai.docente.daily_limit`).
 */
class CuotaDelTutor
{
    public function __construct(
        private readonly string $bolsa = 'tutor',
        private readonly string $claveLimite = 'openai.tutor.daily_limit',
    ) {
    }

    /** La cuota del personal: docentes y administradores, aparte de la de los estudiantes. */
    public static function delPersonal(): self
    {
        return new self('docente', 'openai.docente.daily_limit');
    }

    public function limite(): int
    {
        return (int) config($this->claveLimite, 5);
    }

    public function activa(): bool
    {
        return $this->limite() > 0;
    }

    /** Apunta una consulta. `false` si el estudiante ya gastó las de hoy (y no apunta nada). */
    public function reservar(string $estudianteId): bool
    {
        if (!$this->activa()) {
            return true;
        }

        $clave = $this->clave($estudianteId);

        Cache::add($clave, 0, $this->segundosHastaElFinDelDia());

        if ((int) Cache::increment($clave) > $this->limite()) {
            Cache::decrement($clave);

            return false;
        }

        return true;
    }

    /** Deshace una consulta que no llegó al modelo. Nunca baja de cero. */
    public function devolver(string $estudianteId): void
    {
        if (!$this->activa()) {
            return;
        }

        $clave = $this->clave($estudianteId);

        if ((int) Cache::get($clave, 0) > 0) {
            Cache::decrement($clave);
        }
    }

    /**
     * Lo que se le enseña al estudiante: cuántas ha usado, cuántas le quedan y cuándo se renueva.
     *
     * @return array{limit:int|null, used:int, remaining:int|null, resets_at:string}
     */
    public function estado(string $estudianteId): array
    {
        $fin = $this->finDelDia();

        if (!$this->activa()) {
            return ['limit' => null, 'used' => 0, 'remaining' => null, 'resets_at' => $fin->toIso8601String()];
        }

        $usadas = min($this->limite(), max(0, (int) Cache::get($this->clave($estudianteId), 0)));

        return [
            'limit'     => $this->limite(),
            'used'      => $usadas,
            'remaining' => $this->limite() - $usadas,
            'resets_at' => $fin->toIso8601String(),
        ];
    }

    private function clave(string $estudianteId): string
    {
        return "ai:cuota:{$this->bolsa}:{$estudianteId}:" . $this->ahora()->format('Y-m-d');
    }

    private function ahora(): Carbon
    {
        return Carbon::now((string) config('openai.tutor.daily_timezone', 'America/Costa_Rica'));
    }

    private function finDelDia(): Carbon
    {
        return $this->ahora()->endOfDay()->addSecond();
    }

    private function segundosHastaElFinDelDia(): int
    {
        // Un margen de una hora: la clave de ayer nunca se usa, pero no debe caducar antes de que acabe el día.
        return max(60, (int) $this->ahora()->diffInSeconds($this->finDelDia(), true)) + 3600;
    }
}
