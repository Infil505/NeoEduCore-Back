<?php

namespace App\Services\Admin;

use App\Models\Admin\Institution;
use App\Support\CatalogoMaterias;
use App\Support\RelacionesEnLinea;
use Illuminate\Support\Arr;
use App\Support\TenantCache;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;

/**
 * Agregados de los reportes académicos, en la forma que necesitan los gráficos
 * del frontend (barras, líneas y pastel) y el PDF que este arma.
 *
 * El backend no dibuja ni maqueta: entrega series ya calculadas. Cada serie
 * sale de **una sola consulta agregada**, no de recorrer intentos en PHP; es lo
 * que sostiene el requisito no funcional de resolver el reporte de 1.000
 * estudiantes en menos de 5 segundos.
 */
class ReportMetricsService
{
    /** Nota de aprobación por defecto si la institución no la ha configurado. */
    public const DEFAULT_PASSING_PERCENTAGE = 65.0;

    /** Puntos que devuelve la serie de evolución si no se pide otra cosa. */
    public const DEFAULT_TREND_POINTS = 20;

    /** Tope duro de la serie de evolución, para que no se pida el historial entero. */
    public const MAX_TREND_POINTS = 100;

    /** Rangos de nota del histograma, como [etiqueta, desde, hasta_exclusive]. */
    private const SCORE_RANGES = [
        ['0-49', 0, 50],
        ['50-59', 50, 60],
        ['60-69', 60, 70],
        ['70-79', 70, 80],
        ['80-89', 80, 90],
        ['90-100', 90, null],
    ];

    /**
     * Reporte grupal de un examen: totales, histograma por rango de nota
     * (barras) y reparto por nivel de desempeño (pastel).
     *
     * @return array<string,mixed>
     */
    public function examSummary(Exam $exam): array
    {
        $passing = $this->passingPercentage($exam->institution_id);
        $cuts    = $this->levelCuts($passing);

        $row   = $this->examAggregates($exam, $passing, $cuts);
        $total = (int) ($row->total ?? 0);

        return [
            'exam' => [
                'id'      => $exam->id,
                'title'   => $exam->title,
                'grade'   => $exam->grade,
                // Materia del catálogo en caché y docente en la misma consulta de los
                // agregados: ni una consulta más (cada viaje a la base remota cuesta ~0,4 s).
                'subject' => $exam->subject_id ? CatalogoMaterias::delCentro($exam->institution_id)->get($exam->subject_id)?->name : null,
                'teacher' => $row->teacher_name ?? null,
            ],
            'passing_percentage' => $passing,
            'totals' => [
                'attempts'  => $total,
                'average'   => $this->round($row->average ?? 0),
                'median'    => $this->round($row->median ?? 0),
                'best'      => $this->round($row->best ?? 0),
                'worst'     => $this->round($row->worst ?? 0),
                'passed'    => (int) ($row->passed ?? 0),
                'failed'    => $total - (int) ($row->passed ?? 0),
                'pass_rate' => $this->share((int) ($row->passed ?? 0), $total),
            ],
            'score_distribution' => $this->scoreDistribution($row, $total),
            'performance_levels' => $this->performanceLevels($row, $total, $cuts),
        ];
    }

    /**
     * Reporte individual de un estudiante: totales, evolución de la nota en el
     * tiempo (líneas) y dominio por materia (barras).
     *
     * @return array<string,mixed>
     */
    public function studentSummary(Student $student, int $trendPoints = self::DEFAULT_TREND_POINTS): array
    {
        $student->loadMissing('user:id,full_name');

        $passing = $this->passingPercentage($student->institution_id);
        $points  = max(1, min($trendPoints, self::MAX_TREND_POINTS));

        // Totales y últimos intentos en UNA consulta: los totales se calculan con funciones de
        // ventana (antes del LIMIT) y van en cada fila. El más reciente es el «último».
        [$recientes, $row] = $this->recentAttemptsWithTotals($student, $points, $passing);
        $total  = (int) ($row->total ?? 0);
        $ultimo = $recientes->first();

        return [
            'student' => [
                'user_id'      => $student->user_id,
                'full_name'    => $student->user?->full_name,
                'student_code' => $student->student_code,
                'grade'        => $student->grade,
                'section'      => $student->section,
            ],
            'passing_percentage' => $passing,
            'totals' => [
                'attempts'  => $total,
                'average'   => $this->round($row->average ?? 0),
                'best'      => $this->round($row->best ?? 0),
                'last'      => $this->round($ultimo !== null && (float) $ultimo->max_score > 0 ? (float) $ultimo->score / (float) $ultimo->max_score * 100 : 0),
                'passed'    => (int) ($row->passed ?? 0),
                'pass_rate' => $this->share((int) ($row->passed ?? 0), $total),
            ],
            'score_trend'     => $this->scoreTrend($recientes),
            'subject_mastery' => $this->subjectMastery($student),
        ];
    }

    /**
     * Nota mínima de aprobación de la institución (0-100). Vive en
     * `institutions.settings` para que cada centro fije la suya desde
     * `PUT /api/system/config`.
     *
     * Se relee en cada llamada a propósito: bajo Octane el worker sobrevive
     * entre peticiones, así que un caché `static` dejaría congelado el valor
     * anterior después de que un admin lo cambie —y, peor, lo compartiría entre
     * instituciones—. Es una query por reporte; el ahorro no compensa el riesgo.
     */
    public function passingPercentage(?string $institutionId): float
    {
        if ($institutionId === null) {
            return self::DEFAULT_PASSING_PERCENTAGE;
        }

        // Fila cruda del centro, de la caché por centro (`TenantCache::CONFIG`, la
        // misma que usa el panel del alumno): se invalida al guardar la
        // institución, así que un cambio de la nota mínima se ve enseguida. No es
        // una `static`: va por centro y la limpia el observador, que es lo que el
        // aviso de Octane de más arriba exigía.
        $fila = TenantCache::remember(
            $institutionId, TenantCache::CONFIG, 'institution-row', 600,
            fn () => Institution::query()->whereKey($institutionId)->toBase()->first()
        );
        $guardados = $fila?->settings ?? [];
        if (is_string($guardados)) {
            $guardados = json_decode($guardados, true) ?: [];
        }

        $settings = array_merge(Institution::$defaultSettings, (array) $guardados);

        return (float) ($settings['passing_percentage'] ?? self::DEFAULT_PASSING_PERCENTAGE);
    }

    /* =========================================================================
     | Consultas agregadas
     ========================================================================= */

    /**
     * Totales, histograma y niveles del examen en un solo SELECT. Los
     * `COUNT(*) FILTER` se resuelven en la misma pasada sobre la tabla, así que
     * añadir bandas no añade consultas.
     *
     * @param  array{passing:float,satisfactory:float,advanced:float}  $cuts
     */
    private function examAggregates(Exam $exam, float $passing, array $cuts): object
    {
        $pct = '(score / max_score * 100)';

        $selects = [
            'COUNT(*) AS total',
            "COALESCE(AVG({$pct}), 0) AS average",
            "COALESCE(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$pct}), 0) AS median",
            "COALESCE(MAX({$pct}), 0) AS best",
            "COALESCE(MIN({$pct}), 0) AS worst",
            "COUNT(*) FILTER (WHERE {$pct} >= ?) AS passed",
        ];
        $bindings = [$passing];

        foreach (self::SCORE_RANGES as $i => [$label, $from, $to]) {
            if ($to === null) {
                $selects[]  = "COUNT(*) FILTER (WHERE {$pct} >= ?) AS range_{$i}";
                $bindings[] = $from;
                continue;
            }
            $selects[]  = "COUNT(*) FILTER (WHERE {$pct} >= ? AND {$pct} < ?) AS range_{$i}";
            $bindings[] = $from;
            $bindings[] = $to;
        }

        $selects[]  = "COUNT(*) FILTER (WHERE {$pct} >= ?) AS lvl_advanced";
        $bindings[] = $cuts['advanced'];

        $selects[]  = "COUNT(*) FILTER (WHERE {$pct} >= ? AND {$pct} < ?) AS lvl_satisfactory";
        $bindings[] = $cuts['satisfactory'];
        $bindings[] = $cuts['advanced'];

        $selects[]  = "COUNT(*) FILTER (WHERE {$pct} >= ? AND {$pct} < ?) AS lvl_in_progress";
        $bindings[] = $cuts['passing'];
        $bindings[] = $cuts['satisfactory'];

        $selects[]  = "COUNT(*) FILTER (WHERE {$pct} < ?) AS lvl_needs_support";
        $bindings[] = $cuts['passing'];

        // Nombre del docente en la misma consulta (subselect escalar, sin relación aparte).
        $selects[]  = '(SELECT u.full_name FROM users u WHERE u.id = ? AND u.institution_id = ?) AS teacher_name';
        $bindings[] = $exam->created_by_teacher_id;
        $bindings[] = $exam->institution_id;

        return $this->gradedAttempts()
            ->where('exam_id', $exam->id)
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();
    }

    /**
     * Los N intentos calificados más recientes (del más nuevo al más antiguo), con su examen
     * unido (`RelacionesEnLinea`), y los totales de TODOS los calificados del alumno.
     *
     * Los totales salen con funciones de ventana en las mismas filas (se calculan antes del
     * `LIMIT`), así que el historial completo no se trae y no hace falta otra consulta.
     *
     * @return array{0:\Illuminate\Database\Eloquent\Collection<int,ExamAttempt>,1:object}
     */
    private function recentAttemptsWithTotals(Student $student, int $points, float $passing): array
    {
        $pct = '(exam_attempts.score / exam_attempts.max_score * 100)';

        $intentos = RelacionesEnLinea::unir(
            $this->gradedAttempts()->where('exam_attempts.student_user_id', $student->user_id),
            ['exam' => ['exams', 'exam_id', ['id', 'title', 'subject_id']]]
        )
            ->selectRaw("
                COUNT(*) OVER ()                                    AS w_total,
                COALESCE(AVG({$pct}) OVER (), 0)                    AS w_average,
                COALESCE(MAX({$pct}) OVER (), 0)                    AS w_best,
                COUNT(*) FILTER (WHERE {$pct} >= ?) OVER ()         AS w_passed
            ", [$passing])
            ->orderByDesc('exam_attempts.submitted_at')
            ->limit($points)
            ->get();

        $primero = $intentos->first();
        $totales = (object) [
            'total'   => $primero?->getAttribute('w_total') ?? 0,
            'average' => $primero?->getAttribute('w_average') ?? 0,
            'best'    => $primero?->getAttribute('w_best') ?? 0,
            'passed'  => $primero?->getAttribute('w_passed') ?? 0,
        ];

        foreach ($intentos as $intento) {
            $intento->setRawAttributes(
                Arr::except($intento->getAttributes(), ['w_total', 'w_average', 'w_best', 'w_passed']),
                true
            );
        }
        RelacionesEnLinea::hidratar($intentos, ['exam' => Exam::class]);

        // Materias del catálogo en caché, no una consulta más.
        $materias = CatalogoMaterias::delCentro($student->institution_id);
        $intentos->each(fn (ExamAttempt $a) => $a->exam?->setRelation('subject', $materias->get($a->exam->subject_id)));

        return [$intentos, $totales];
    }

    /**
     * Serie de evolución, del intento más antiguo al más reciente: es el orden
     * en que se lee un gráfico de líneas. Recibe los N últimos (del más nuevo al
     * más antiguo) y los invierte, en vez de traer el historial completo.
     *
     * @param  \Illuminate\Support\Collection<int,ExamAttempt>  $recientes
     * @return array<int,array<string,mixed>>
     */
    private function scoreTrend($recientes): array
    {
        return $recientes
            ->reverse()
            ->values()
            ->map(fn (ExamAttempt $a) => [
                'attempt_id'   => $a->id,
                'exam_id'      => $a->exam_id,
                'exam_title'   => $a->exam?->title,
                'subject'      => $a->exam?->subject?->name,
                'score'        => (float) $a->score,
                'max_score'    => (float) $a->max_score,
                'percentage'   => $a->percentage,
                'submitted_at' => $a->submitted_at,
            ])
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function subjectMastery(Student $student): array
    {
        $materias = CatalogoMaterias::delCentro($student->institution_id);

        return StudentProgress::query()
            ->where('student_user_id', $student->user_id)
            ->get()
            ->each(fn (StudentProgress $p) => $p->setRelation('subject', $materias->get($p->subject_id)))
            ->sortByDesc('mastery_percentage')
            ->values()
            ->map(fn (StudentProgress $p) => [
                'subject_id'         => $p->subject_id,
                'subject'            => $p->subject?->name,
                'mastery_percentage' => $this->round($p->mastery_percentage),
                'updated_at'         => $p->updated_at,
            ])
            ->all();
    }

    /* =========================================================================
     | Forma de las series
     ========================================================================= */

    /** @return array<int,array<string,mixed>> */
    private function scoreDistribution(object $row, int $total): array
    {
        $out = [];

        foreach (self::SCORE_RANGES as $i => [$label, $from, $to]) {
            $count = (int) ($row->{'range_' . $i} ?? 0);

            $out[] = [
                'range' => $label,
                'from'  => $from,
                'to'    => $to,   // null en el último tramo: 90 en adelante
                'count' => $count,
                'share' => $this->share($count, $total),
            ];
        }

        return $out;
    }

    /**
     * Cuatro niveles de desempeño, de mejor a peor. Son categorías **ordenadas**:
     * el frontend debe pintarlas con una rampa de un solo tono (claro→oscuro),
     * no con colores categóricos sueltos.
     *
     * @param  array{passing:float,satisfactory:float,advanced:float}  $cuts
     * @return array<int,array<string,mixed>>
     */
    private function performanceLevels(object $row, int $total, array $cuts): array
    {
        $definitions = [
            ['advanced',      'Avanzado',       $cuts['advanced'],     null],
            ['satisfactory',  'Satisfactorio',  $cuts['satisfactory'], $cuts['advanced']],
            ['in_progress',   'En proceso',     $cuts['passing'],      $cuts['satisfactory']],
            ['needs_support', 'Requiere apoyo', 0.0,                   $cuts['passing']],
        ];

        return array_map(function (array $d) use ($row, $total) {
            $count = (int) ($row->{'lvl_' . $d[0]} ?? 0);

            return [
                'key'   => $d[0],
                'label' => $d[1],
                'from'  => $d[2],
                'to'    => $d[3],
                'count' => $count,
                'share' => $this->share($count, $total),
            ];
        }, $definitions);
    }

    /**
     * Cortes de los niveles de desempeño. Cuelgan de la nota de aprobación con
     * `max()` para que no se crucen si una institución fija una nota mínima
     * alta: con `passing = 95`, «Avanzado» pasa a ser ≥95 y las bandas siguen
     * ordenadas en vez de solaparse.
     *
     * @return array{passing:float,satisfactory:float,advanced:float}
     */
    private function levelCuts(float $passing): array
    {
        return [
            'passing'      => $passing,
            'satisfactory' => max(80.0, $passing),
            'advanced'     => max(90.0, $passing),
        ];
    }

    /* =========================================================================
     | Helpers
     ========================================================================= */

    /**
     * Intentos entregados y calificables. `max_score > 0` descarta los intentos
     * sin puntaje máximo, que provocarían división por cero en los porcentajes.
     */
    private function gradedAttempts()
    {
        return ExamAttempt::query()
            ->whereNotNull('submitted_at')
            ->where('max_score', '>', 0);
    }

    private function round(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function share(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 2) : 0.0;
    }
}
