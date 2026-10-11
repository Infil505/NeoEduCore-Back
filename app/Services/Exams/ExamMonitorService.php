<?php

namespace App\Services\Exams;

use App\Enums\UserStatus;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento en vivo de un examen para el docente: quién lo está
 * presentando, quién ya lo entregó (y con qué nota) y a quién le falta.
 *
 * Los destinatarios son los estudiantes activos matriculados hoy en las
 * secciones destino del examen. Quien lo presentó y luego cambió de sección
 * también aparece: su intento sigue contando.
 */
class ExamMonitorService
{
    public const SIN_EMPEZAR = 'not_started';
    public const PRESENTANDO = 'in_progress';
    public const EN_PAUSA = 'paused';
    public const ENTREGADO = 'submitted';
    public const VENCIDO = 'expired';

    public function __construct(private readonly ExamAttemptRulesService $rules)
    {
    }

    /** Detalle por estudiante + resumen de un examen. */
    public function monitor(Exam $exam): array
    {
        $destinatarios = $this->destinatarios(collect([$exam->id]))->get($exam->id, collect());
        $intentos = ExamAttempt::query()->where('exam_id', $exam->id)->orderBy('attempt_number')->get()->groupBy('student_user_id');

        $ids = $destinatarios->pluck('user_id')->merge($intentos->keys())->unique()->values();
        $alumnos = DB::table('users')->whereIn('id', $ids)->pluck('full_name', 'id');
        $fichas = Student::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $secciones = $this->seccionesActuales($ids);

        $filas = $ids->map(function (string $userId) use ($exam, $intentos, $alumnos, $fichas, $secciones) {
            $suyos = $intentos->get($userId, collect());
            $entregados = $suyos->whereNotNull('submitted_at');
            $ultimo = $entregados->sortByDesc('submitted_at')->first();
            $mejor = $entregados->sortByDesc(fn (ExamAttempt $a) => $a->percentage)->first();
            $abierto = $suyos->whereNull('submitted_at')->sortByDesc('attempt_number')->first();

            return [
                'student_user_id' => $userId,
                'full_name' => $alumnos->get($userId, 'Estudiante'),
                'section' => $secciones->get($userId),
                'status' => $this->estado($exam, $abierto, $entregados->isNotEmpty(), $fichas->get($userId)),
                'attempts_used' => $entregados->count(),
                'started_at' => $abierto?->started_at ?? $ultimo?->started_at,
                'submitted_at' => $ultimo?->submitted_at,
                'last_attempt_id' => $ultimo?->id,
                'score' => $mejor ? (float) $mejor->score : null,
                'max_score' => $mejor ? (float) $mejor->max_score : null,
                'percentage' => $mejor?->percentage,
                'pending_review' => $entregados->contains(fn (ExamAttempt $a) => ($a->grade_status?->value ?? $a->grade_status) === 'pending'),
            ];
        });

        // Primero quien lo está presentando, luego quien falta, luego quien ya entregó.
        $orden = [self::PRESENTANDO => 0, self::EN_PAUSA => 1, self::SIN_EMPEZAR => 2, self::VENCIDO => 3, self::ENTREGADO => 4];
        $filas = $filas->sortBy([fn ($a, $b) => $orden[$a['status']] <=> $orden[$b['status']], fn ($a, $b) => strcmp($a['full_name'], $b['full_name'])])->values();

        return ['summary' => $this->contar($filas), 'students' => $filas];
    }

    /**
     * Resumen ligero de varios exámenes (para la columna de la tabla):
     * exam_id => [total, in_progress, submitted, pending].
     */
    public function resumen(Collection $exams): array
    {
        if ($exams->isEmpty()) {
            return [];
        }

        $destinatarios = $this->destinatarios($exams->pluck('id'));
        $intentos = ExamAttempt::query()->whereIn('exam_id', $exams->pluck('id'))->get()->groupBy('exam_id');
        $fichas = Student::query()->whereIn('user_id', $intentos->flatten()->pluck('student_user_id')->unique())->get()->keyBy('user_id');

        return $exams->mapWithKeys(function (Exam $exam) use ($destinatarios, $intentos, $fichas) {
            $porAlumno = $intentos->get($exam->id, collect())->groupBy('student_user_id');
            $ids = $destinatarios->get($exam->id, collect())->pluck('user_id')->merge($porAlumno->keys())->unique();

            $filas = $ids->map(function (string $userId) use ($exam, $porAlumno, $fichas) {
                $suyos = $porAlumno->get($userId, collect());
                $abierto = $suyos->whereNull('submitted_at')->sortByDesc('attempt_number')->first();

                return ['status' => $this->estado($exam, $abierto, $suyos->whereNotNull('submitted_at')->isNotEmpty(), $fichas->get($userId))];
            });

            return [$exam->id => $this->contar($filas)];
        })->all();
    }

    private function estado(Exam $exam, ?ExamAttempt $abierto, bool $yaEntrego, ?Student $ficha): string
    {
        if ($abierto) {
            $plazo = $this->rules->plazoDeEntrega($exam, $abierto, $ficha);
            if (!$plazo || now()->lte($plazo)) {
                return $abierto->paused_at ? self::EN_PAUSA : self::PRESENTANDO;
            }
            // Se le acabó el tiempo sin entregar este intento.
            return $yaEntrego ? self::ENTREGADO : self::VENCIDO;
        }

        return $yaEntrego ? self::ENTREGADO : self::SIN_EMPEZAR;
    }

    private function contar(Collection $filas): array
    {
        $porEstado = $filas->countBy('status');

        return [
            'total' => $filas->count(),
            'in_progress' => $porEstado->get(self::PRESENTANDO, 0) + $porEstado->get(self::EN_PAUSA, 0),
            'submitted' => $porEstado->get(self::ENTREGADO, 0),
            'not_started' => $porEstado->get(self::SIN_EMPEZAR, 0),
            'expired' => $porEstado->get(self::VENCIDO, 0),
        ];
    }

    /** exam_id => estudiantes activos matriculados hoy en sus secciones destino. */
    private function destinatarios(Collection $examIds): Collection
    {
        return DB::table('exam_targets as et')
            ->join('group_students as gs', fn ($j) => $j->on('gs.group_id', '=', 'et.group_id')->whereNull('gs.left_at'))
            ->join('users as u', 'u.id', '=', 'gs.student_user_id')
            ->whereIn('et.exam_id', $examIds)
            ->where('u.status', UserStatus::Active->value)
            ->select('et.exam_id', 'gs.student_user_id as user_id')
            ->distinct()
            ->get()
            ->groupBy('exam_id');
    }

    /** user_id => sección actual («6-1»). */
    private function seccionesActuales(Collection $userIds): Collection
    {
        return DB::table('group_students as gs')
            ->join('groups as g', 'g.id', '=', 'gs.group_id')
            ->whereIn('gs.student_user_id', $userIds)
            ->whereNull('gs.left_at')
            ->pluck('g.section', 'gs.student_user_id');
    }
}
