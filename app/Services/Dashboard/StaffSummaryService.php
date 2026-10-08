<?php

namespace App\Services\Dashboard;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\StudyResource;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use Illuminate\Support\Facades\DB;

/**
 * Cifras reales del panel de inicio del personal (admin y docente).
 *
 * El overview trae listas recortadas (las 20 cuentas o estudiantes más
 * recientes) porque son para mostrar, no para contar. Antes el panel contaba
 * esas listas y salían «20 estudiantes» con 75 matriculados. Aquí se cuenta en
 * la base de datos, con el mismo alcance que el resto del sistema: el docente
 * ve sus grupos, sus estudiantes y sus exámenes; el admin, el centro entero.
 */
class StaffSummaryService
{
    public function resumen(User $user, bool $esDocente): array
    {
        $centro = $user->institution_id;

        // Grupos del alcance con sus estudiantes activos reales (no el contador
        // guardado ni la lista recortada).
        $grupos = DB::table('groups as g')
            ->where('g.institution_id', $centro)
            ->when($esDocente, fn ($q) => $q->whereIn('g.id', $this->gruposDelDocente($user->id, $centro)))
            ->leftJoin('group_students as gs', fn ($j) => $j->on('gs.group_id', '=', 'g.id')->whereNull('gs.left_at'))
            ->groupBy('g.id', 'g.name', 'g.grade', 'g.section', 'g.year')
            ->orderByDesc('g.year')->orderBy('g.grade')->orderBy('g.section')
            ->get(['g.id', 'g.name', 'g.grade', 'g.section', 'g.year', DB::raw('COUNT(gs.student_user_id) as students')]);

        // Estudiantes alcanzados (distintos: uno puede estar en dos grupos del docente).
        $estudiantes = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.institution_id', $centro)
            ->when($esDocente, fn ($q) => $q->whereIn('s.user_id', $this->estudiantesDelDocente($user->id, $centro)));

        $examenes = Exam::query()->when($esDocente, fn ($q) => $q->where('created_by_teacher_id', $user->id));
        $porEstado = (clone $examenes)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $idsExamenes = (clone $examenes)->select('id');

        $intentos = DB::table('exam_attempts')->where('institution_id', $centro)->whereNotNull('submitted_at')->whereIn('exam_id', $idsExamenes);
        $promedio = (clone $intentos)->where('max_score', '>', 0)->selectRaw('AVG(score / max_score * 100) as pct')->value('pct');

        $porRevisar = DB::table('student_answers as sa')
            ->join('exam_attempts as a', 'a.id', '=', 'sa.attempt_id')
            ->where('sa.institution_id', $centro)
            ->where('sa.review_status', 'needs_review')
            ->whereIn('a.exam_id', $idsExamenes)
            ->count();

        // Lo que está por venir en el calendario visible para este usuario.
        $proximos = CalendarEvent::query()->visibleTo($user)->where('end_at', '>=', now());
        $siguiente = (clone $proximos)->orderBy('start_at')->first(['id', 'title', 'start_at', 'event_type']);

        $resumen = [
            'groups' => $grupos->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'grade' => (int) $g->grade,
                'section' => $g->section,
                'year' => $g->year,
                'students' => (int) $g->students,
            ])->values(),
            'students' => [
                'total' => (clone $estudiantes)->count(),
                'active' => (clone $estudiantes)->where('u.status', UserStatus::Active->value)->count(),
            ],
            'subjects' => $esDocente
                ? DB::table('teacher_assignments')->where('institution_id', $centro)->where('teacher_user_id', $user->id)->distinct()->count('subject_id')
                : DB::table('subjects')->where('institution_id', $centro)->count(),
            'exams' => [
                'total' => (int) $porEstado->sum(),
                'draft' => (int) ($porEstado['draft'] ?? 0),
                'published' => (int) ($porEstado['published'] ?? 0),
                'active' => (int) ($porEstado['active'] ?? 0),
                'completed' => (int) ($porEstado['completed'] ?? 0),
            ],
            'attempts_submitted' => (clone $intentos)->count(),
            'average_pct' => $promedio !== null ? round((float) $promedio, 1) : null,
            'pending_reviews' => $porRevisar,
            'upcoming_events' => (clone $proximos)->count(),
            'next_event' => $siguiente,
            'resources' => StudyResource::query()->visibleTo($user)->count(),
        ];

        if (!$esDocente) {
            $resumen['users'] = $this->usuarios($centro);
            $resumen['attention'] = $this->puntosDeAtencion($centro);
        }

        return $resumen;
    }

    /** Cuentas del centro por rol y estado. */
    private function usuarios(string $centro): array
    {
        $filas = DB::table('users')->where('institution_id', $centro)
            ->selectRaw('user_type, status, COUNT(*) as total')
            ->groupBy('user_type', 'status')
            ->get();

        $contar = fn (string $tipo, ?string $estado = null) => (int) $filas
            ->where('user_type', $tipo)
            ->when($estado, fn ($c) => $c->where('status', $estado))
            ->sum('total');

        return [
            'total' => (int) $filas->sum('total'),
            'active' => (int) $filas->where('status', UserStatus::Active->value)->sum('total'),
            'students' => $contar(UserType::Student->value),
            'students_active' => $contar(UserType::Student->value, UserStatus::Active->value),
            'teachers' => $contar(UserType::Teacher->value),
            'teachers_active' => $contar(UserType::Teacher->value, UserStatus::Active->value),
            'admins' => $contar(UserType::Admin->value),
        ];
    }

    /** Lo que el administrador debería resolver: huecos en la matrícula y la docencia. */
    private function puntosDeAtencion(string $centro): array
    {
        $estudiantesActivos = DB::table('users')->where('institution_id', $centro)
            ->where('user_type', UserType::Student->value)->where('status', UserStatus::Active->value);

        return [
            'students_without_section' => (clone $estudiantesActivos)
                ->whereNotExists(fn ($q) => $q->from('group_students')->whereColumn('group_students.student_user_id', 'users.id')->whereNull('left_at'))
                ->count(),
            'teachers_without_assignments' => DB::table('users')->where('institution_id', $centro)
                ->where('user_type', UserType::Teacher->value)->where('status', UserStatus::Active->value)
                ->whereNotExists(fn ($q) => $q->from('teacher_assignments')->whereColumn('teacher_assignments.teacher_user_id', 'users.id'))
                ->count(),
            'groups_without_teacher' => DB::table('groups')->where('institution_id', $centro)
                ->whereNotExists(fn ($q) => $q->from('teacher_assignments')->whereColumn('teacher_assignments.group_id', 'groups.id'))
                ->count(),
            'pending_accounts' => DB::table('users')->where('institution_id', $centro)->where('status', UserStatus::Inactive->value)->count(),
        ];
    }

    private function gruposDelDocente(string $teacherId, string $centro)
    {
        return DB::table('teacher_assignments')->select('group_id')->where('teacher_user_id', $teacherId)->where('institution_id', $centro);
    }

    private function estudiantesDelDocente(string $teacherId, string $centro)
    {
        return DB::table('group_students as gs')
            ->select('gs.student_user_id')
            ->join('teacher_assignments as ta', 'ta.group_id', '=', 'gs.group_id')
            ->whereNull('gs.left_at')
            ->where('ta.teacher_user_id', $teacherId)
            ->where('ta.institution_id', $centro);
    }
}
