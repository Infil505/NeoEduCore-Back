<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiIncidentType;
use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiTutorIncident;
use App\Models\Admin\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de plataforma del tutor IA — **solo superadministrador** (D5).
 *
 * Responde a la parte de [173] que hasta ahora no se podía ni comprobar: «el
 * sistema registrará incidencias» y «más del 75 % de los mensajes deben superar
 * la validación». Con los bloqueos guardados en `ai_tutor_incidents`, el
 * criterio pasa de ser una frase del informe a un número que se mira.
 *
 * **Por qué el superadministrador y no el admin del centro.** El interés de este
 * número es de plataforma: decide si el filtro está bien calibrado, si un modelo
 * nuevo se porta peor que el anterior o si un centro concreto se desvía del
 * resto. Es la misma persona que ya administra instituciones, y es externa a
 * todas ellas ([roles]: el superadmin no tiene `institution_id`, y esa ausencia
 * es el aislamiento).
 *
 * **Solo agregados.** Ni un `student_user_id` sale por aquí, aunque la tabla lo
 * guarde. Un externo a la institución contando cuántas veces bloqueó el filtro a
 * un menor concreto sería justo lo contrario de lo que [394] promete. La
 * frontera es la misma que `tutorUsage()` aplica al docente.
 *
 * **`withoutGlobalScope('tenant')` es obligatorio aquí.** `AiChatSession` lleva
 * `TenantScoped`, y el superadministrador no tiene tenant enlazado: sin quitar
 * el scope, el propio scope lanzaría una excepción por consultar sin contexto.
 * Es la excepción legítima a la regla, no un descuido.
 */
class PlatformMetricsController extends Controller
{
    /** Lo que [173] exige superar. */
    private const UMBRAL_VALIDACION = 75.0;

    /** Ventana por defecto si no se pide otra cosa. */
    private const DIAS_POR_DEFECTO = 30;

    public function aiTutor(Request $request)
    {
        $filtros = $request->validate([
            'from'           => ['sometimes', 'date'],
            'to'             => ['sometimes', 'date', 'after_or_equal:from'],
            'institution_id' => ['sometimes', 'uuid'],
        ]);

        $desde = isset($filtros['from'])
            ? Carbon::parse($filtros['from'])->startOfDay()
            : now()->subDays(self::DIAS_POR_DEFECTO)->startOfDay();

        $hasta = isset($filtros['to'])
            ? Carbon::parse($filtros['to'])->endOfDay()
            : now()->endOfDay();

        $institucion = $filtros['institution_id'] ?? null;

        $incidencias = $this->incidenciasPorTipo($desde, $hasta, $institucion);
        $mensajes    = $this->mensajesDelTutor($desde, $hasta, $institucion);

        $deValidacion = collect($incidencias)
            ->only(AiIncidentType::deValidacion())
            ->sum();

        return response()->json([
            'data' => [
                'window' => [
                    'from' => $desde->toIso8601String(),
                    'to'   => $hasta->toIso8601String(),
                ],
                'totals' => [
                    'institutions'          => Institution::count(),
                    'sessions'              => $mensajes['sessions'],
                    'assistant_messages'    => $mensajes['assistant_messages'],
                    'incidents'             => array_sum($incidencias),
                    'validation_incidents'  => $deValidacion,
                    'validation_pass_rate'  => $this->tasa($mensajes['assistant_messages'], $deValidacion),
                    'threshold'             => self::UMBRAL_VALIDACION,
                    'meets_threshold'       => $this->tasa($mensajes['assistant_messages'], $deValidacion)
                        >= self::UMBRAL_VALIDACION,
                ],
                'by_type'        => $incidencias,
                'by_stage'       => $this->incidenciasPorEtapa($desde, $hasta, $institucion),
                'by_institution' => $this->porInstitucion($desde, $hasta, $institucion),
                'daily'          => $this->porDia($desde, $hasta, $institucion),
            ],
        ]);
    }

    /**
     * Porcentaje de respuestas del tutor que superaron la validación.
     *
     * Sin mensajes devuelve 100: un sistema que no ha respondido nada no ha
     * incumplido nada. Dar 0 haría que un centro recién creado apareciera en
     * rojo, que es ruido y no información.
     */
    private function tasa(int $mensajes, int $incidencias): float
    {
        if ($mensajes <= 0) {
            return 100.0;
        }

        return round(max(0, $mensajes - $incidencias) / $mensajes * 100, 2);
    }

    /** @return array<string,int> tipo => total */
    private function incidenciasPorTipo(Carbon $desde, Carbon $hasta, ?string $institucion): array
    {
        $base = array_fill_keys(array_column(AiIncidentType::cases(), 'value'), 0);

        $contados = $this->incidencias($desde, $hasta, $institucion)
            ->select('type', DB::raw('COUNT(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($t) => (int) $t)
            ->all();

        // Los tipos sin incidencias salen en 0 y no ausentes: un panel con
        // columnas que aparecen y desaparecen no se puede comparar entre fechas.
        return array_merge($base, $contados);
    }

    /** @return array<string,int> etapa => total */
    private function incidenciasPorEtapa(Carbon $desde, Carbon $hasta, ?string $institucion): array
    {
        return $this->incidencias($desde, $hasta, $institucion)
            ->select('stage', DB::raw('COUNT(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->map(fn ($t) => (int) $t)
            ->all();
    }

    /**
     * Desglose por centro: es lo que permite ver si el problema es del sistema
     * o de un colegio concreto. Lleva nombre de institución —el superadmin las
     * administra— pero ningún dato de personas.
     */
    private function porInstitucion(Carbon $desde, Carbon $hasta, ?string $institucion): array
    {
        $incidencias = $this->incidencias($desde, $hasta, $institucion)
            ->select('institution_id', DB::raw('COUNT(*) as total'))
            ->groupBy('institution_id')
            ->pluck('total', 'institution_id');

        $mensajes = $this->sesionesBase($desde, $hasta, $institucion)
            ->select('institution_id', DB::raw('SUM(jsonb_array_length(messages)) as total'))
            ->groupBy('institution_id')
            ->pluck('total', 'institution_id');

        $ids = $incidencias->keys()->merge($mensajes->keys())->unique();

        $nombres = Institution::whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(function ($id) use ($incidencias, $mensajes, $nombres) {
            $asistente = intdiv((int) ($mensajes[$id] ?? 0), 2);
            $total     = (int) ($incidencias[$id] ?? 0);

            return [
                'institution_id'       => $id,
                'name'                 => $nombres[$id] ?? null,
                'assistant_messages'   => $asistente,
                'incidents'            => $total,
                'validation_pass_rate' => $this->tasa($asistente, $total),
            ];
        })->sortBy('validation_pass_rate')->values()->all();
    }

    /** Serie diaria de incidencias, para el gráfico de líneas del panel. */
    private function porDia(Carbon $desde, Carbon $hasta, ?string $institucion): array
    {
        return $this->incidencias($desde, $hasta, $institucion)
            ->select(DB::raw('DATE(occurred_at) as dia'), DB::raw('COUNT(*) as total'))
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->map(fn ($f) => ['date' => (string) $f->dia, 'incidents' => (int) $f->total])
            ->all();
    }

    /**
     * Mensajes del tutor en la ventana.
     *
     * `messages` guarda la conversación entera, alumno y modelo alternándose, y
     * lo que hay que medir son las respuestas **del modelo**: dividir entre dos
     * es exacto porque cada turno escribe siempre el par.
     */
    private function mensajesDelTutor(Carbon $desde, Carbon $hasta, ?string $institucion): array
    {
        $fila = $this->sesionesBase($desde, $hasta, $institucion)
            ->selectRaw('COUNT(*) as sesiones, COALESCE(SUM(jsonb_array_length(messages)), 0) as mensajes')
            ->first();

        return [
            'sessions'           => (int) ($fila->sesiones ?? 0),
            'assistant_messages' => intdiv((int) ($fila->mensajes ?? 0), 2),
        ];
    }

    private function incidencias(Carbon $desde, Carbon $hasta, ?string $institucion)
    {
        return AiTutorIncident::query()
            ->whereBetween('occurred_at', [$desde, $hasta])
            ->when($institucion, fn ($q) => $q->where('institution_id', $institucion));
    }

    /** Ver el docblock de la clase: el superadministrador no tiene tenant. */
    private function sesionesBase(Carbon $desde, Carbon $hasta, ?string $institucion)
    {
        return AiChatSession::query()
            ->withoutGlobalScope('tenant')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($institucion, fn ($q) => $q->where('institution_id', $institucion));
    }
}
