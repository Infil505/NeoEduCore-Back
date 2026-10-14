<?php

namespace App\Console\Commands;

use App\Services\Exams\ExamAutoSubmitService;
use Illuminate\Console\Command;

/**
 * Entrega por el servidor los intentos que se quedaron sin enviar al agotarse el tiempo (cada minuto).
 *
 * Usa el último borrador autoguardado por el frontend. Ver `ExamAutoSubmitService`.
 */
class CerrarIntentosVencidos extends Command
{
    protected $signature = 'exams:cerrar-intentos-vencidos';

    protected $description = 'Entrega los intentos con el tiempo agotado que el alumno no envió, con su último borrador';

    public function handle(ExamAutoSubmitService $servicio): int
    {
        ['entregados' => $entregados, 'fallidos' => $fallidos] = $servicio->entregarVencidos();

        if ($entregados || $fallidos) {
            $this->info("Intentos entregados: {$entregados} · fallidos: {$fallidos}");
        }

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
