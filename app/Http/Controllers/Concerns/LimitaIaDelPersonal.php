<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AI\CuotaDelTutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El tope diario de usos de la IA del PERSONAL (docente o administrador), compartido por todo lo que cuesta una
 * llamada a OpenAI: el asistente, los consejos, el plan de un estudiante y «Redactar con IA» del análisis.
 *
 * Se usa así: `reservarIa()` justo antes de llamar al modelo (devuelve la respuesta 429 si ya no hay cupo) y
 * `devolverIa()` si al final no llegó al modelo (error, rechazo, nada nuevo que redactar). Ver `CuotaDelTutor`.
 */
trait LimitaIaDelPersonal
{
    /** `null` si hay cupo (y lo apunta); si no, la respuesta 429 que se debe devolver. */
    protected function reservarIa(Request $request): ?JsonResponse
    {
        $cuota = CuotaDelTutor::delPersonal();

        if ($cuota->reservar($request->user()->id)) {
            return null;
        }

        $estado = $cuota->estado($request->user()->id);

        return response()->json([
            'message' => "Ya usaste tus {$estado['limit']} usos de la IA de hoy (asistente, consejos, planes y análisis). Se renuevan mañana.",
            'quota'   => $estado,
        ], 429);
    }

    protected function devolverIa(Request $request): void
    {
        CuotaDelTutor::delPersonal()->devolver($request->user()->id);
    }

    /** @return array{limit:int|null, used:int, remaining:int|null, resets_at:string} */
    protected function estadoIa(Request $request): array
    {
        return CuotaDelTutor::delPersonal()->estado($request->user()->id);
    }
}
