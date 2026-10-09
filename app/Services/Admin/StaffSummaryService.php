<?php

namespace App\Services\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Cifras del panel de inicio del personal (admin y docente), contadas en la base
 * y con el alcance del rol.
 *
 * El frontend las pinta tal cual y no cuenta las listas del resumen: esas vienen
 * recortadas a las 20 más recientes y daban números falsos. Cada cifra es una
 * consulta agregada, no una lista cargada para contarla.
 *
 * **El administrador** cuenta el centro entero y todas sus cifras salen de UNA
 * sola consulta (`paraAdmin`): con la base remota cada viaje cuesta ~0,5 s, y
 * hacerlas por separado eran 14. **El docente** (alcance siempre desde
 * `teacher_assignments`, ver `AcotaAlDocente`) cuenta sus aulas, sus alumnos,
 * sus materias y SUS exámenes, y las entregas y revisiones de esos exámenes.
 */
class StaffSummaryService
{
    use AcotaAlDocente;

    /** @return array<string,mixed> con las claves en snake_case que lee `normalizeStaffSummary` */
    public function para(User $user): array
    {
        return $this->esDocente($user) ? $this->paraDocente($user) : $this->paraAdmin($user);
    }

    /* =========================================================
     | Administrador: una consulta para todas las cifras
     ========================================================= */

    private function paraAdmin(User $user): array
    {
        $centro = $user->institution_id;

        // Cada bloque es una tabla derivada de UNA fila; se cruzan todas. Todas
        // filtran por institución: es el mismo alcance que `TenantScoped`.
        $c = DB::selectOne(<<<'SQL'
            SELECT
                e.total AS ex_total, e.borrador AS ex_draft, e.publicado AS ex_published,
                e.activo AS ex_active, e.completado AS ex_completed,
                a.entregadas AS at_submitted, a.promedio AS at_avg,
                p.pendientes AS pending_reviews,
                s.total AS st_total, s.activos AS st_active,
                m.total AS subjects,
                u.total AS us_total, u.activos AS us_active,
                u.alumnos AS us_students, u.alumnos_activos AS us_students_active,
                u.docentes AS us_teachers, u.docentes_activos AS us_teachers_active,
                u.admins AS us_admins, u.pendientes AS pending_accounts,
                (SELECT COUNT(*) FROM students st
                  WHERE st.institution_id = ?
                    AND NOT EXISTS (SELECT 1 FROM group_students gs
                                     WHERE gs.student_user_id = st.user_id
                                       AND gs.institution_id = ? AND gs.left_at IS NULL)) AS students_without_section,
                (SELECT COUNT(*) FROM users t
                  WHERE t.institution_id = ? AND t.user_type = 'teacher'
                    AND NOT EXISTS (SELECT 1 FROM teacher_assignments ta
                                     WHERE ta.teacher_user_id = t.id AND ta.institution_id = ?)) AS teachers_without_assignments,
                (SELECT COUNT(*) FROM groups g
                  WHERE g.institution_id = ?
                    AND NOT EXISTS (SELECT 1 FROM teacher_assignments ta
                                     WHERE ta.group_id = g.id AND ta.institution_id = ?)) AS groups_without_teacher
            FROM
                (SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE status = 'draft') AS borrador,
                        COUNT(*) FILTER (WHERE status = 'published') AS publicado,
                        COUNT(*) FILTER (WHERE status = 'active') AS activo,
                        COUNT(*) FILTER (WHERE status = 'completed') AS completado
                   FROM exams WHERE institution_id = ?) e,
                (SELECT COUNT(*) AS entregadas,
                        AVG(CASE WHEN max_score > 0 THEN score / max_score * 100 END) AS promedio
                   FROM exam_attempts WHERE institution_id = ? AND submitted_at IS NOT NULL) a,
                (SELECT COUNT(*) AS pendientes
                   FROM student_answers WHERE institution_id = ? AND review_status = 'needs_review') p,
                (SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'active') AS activos
                   FROM students WHERE institution_id = ?) s,
                (SELECT COUNT(*) AS total FROM subjects WHERE institution_id = ?) m,
                (SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE status = 'active') AS activos,
                        COUNT(*) FILTER (WHERE user_type = 'student') AS alumnos,
                        COUNT(*) FILTER (WHERE user_type = 'student' AND status = 'active') AS alumnos_activos,
                        COUNT(*) FILTER (WHERE user_type = 'teacher') AS docentes,
                        COUNT(*) FILTER (WHERE user_type = 'teacher' AND status = 'active') AS docentes_activos,
                        COUNT(*) FILTER (WHERE user_type = 'admin') AS admins,
                        COUNT(*) FILTER (WHERE status = 'inactive' OR must_change_password) AS pendientes
                   FROM users WHERE institution_id = ?) u
        SQL, array_fill(0, 12, $centro));

        $grupos = $this->listaDeAulas(Group::query());
        [$proximos, $siguiente] = $this->avisosProximos($user);

        return [
            'groups'   => $grupos,
            'students' => ['total' => (int) $c->st_total, 'active' => (int) $c->st_active],
            'subjects' => (int) $c->subjects,
            'exams'    => [
                'total'     => (int) $c->ex_total,
                'draft'     => (int) $c->ex_draft,
                'published' => (int) $c->ex_published,
                'active'    => (int) $c->ex_active,
                'completed' => (int) $c->ex_completed,
            ],
            'attempts_submitted' => (int) $c->at_submitted,
            'average_pct'        => $c->at_avg === null ? null : round((float) $c->at_avg, 2),
            'pending_reviews'    => (int) $c->pending_reviews,
            'upcoming_events'    => $proximos,
            'next_event'         => $siguiente,
            'resources'          => StudyResource::query()->visibleTo($user)->count(),
            'users'              => [
                'total'            => (int) $c->us_total,
                'active'           => (int) $c->us_active,
                'students'         => (int) $c->us_students,
                'students_active'  => (int) $c->us_students_active,
                'teachers'         => (int) $c->us_teachers,
                'teachers_active'  => (int) $c->us_teachers_active,
                'admins'           => (int) $c->us_admins,
            ],
            'attention'          => [
                'students_without_section'     => (int) $c->students_without_section,
                'teachers_without_assignments' => (int) $c->teachers_without_assignments,
                'groups_without_teacher'       => (int) $c->groups_without_teacher,
                'pending_accounts'             => (int) $c->pending_accounts,
            ],
        ];
    }

    /* =========================================================
     | Docente: solo lo suyo
     ========================================================= */

    private function paraDocente(User $user): array
    {
        $grupos = $this->listaDeAulas(
            Group::query()->whereIn('id', $this->gruposDelDocente($user->id))
        );

        $alumnos = Student::query()
            ->tap(fn ($q) => $this->acotarAEstudiantesDelDocente($q, $user, 'user_id'))
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'active') AS activos")
            ->first();

        $materias = Subject::query()
            ->whereIn('id', $this->materiasDelDocente($user->id))
            ->count();

        $examenes = Exam::query()
            ->where('created_by_teacher_id', $user->id)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $idsDeExamenes = fn () => Exam::query()->where('created_by_teacher_id', $user->id)->select('id');

        $entregas = ExamAttempt::query()
            ->whereNotNull('submitted_at')
            ->whereIn('exam_id', $idsDeExamenes())
            ->selectRaw('COUNT(*) AS entregadas, AVG(CASE WHEN max_score > 0 THEN score / max_score * 100 END) AS promedio')
            ->first();

        $pendientes = StudentAnswer::query()
            ->where('review_status', 'needs_review')
            ->whereIn('attempt_id', ExamAttempt::query()->whereIn('exam_id', $idsDeExamenes())->select('id'))
            ->count();

        [$proximos, $siguiente] = $this->avisosProximos($user);

        return [
            'groups'            => $grupos,
            'students'          => ['total' => (int) $alumnos->total, 'active' => (int) $alumnos->activos],
            'subjects'          => $materias,
            'exams'             => [
                'total'     => (int) $examenes->sum(),
                'draft'     => (int) ($examenes['draft'] ?? 0),
                'published' => (int) ($examenes['published'] ?? 0),
                'active'    => (int) ($examenes['active'] ?? 0),
                'completed' => (int) ($examenes['completed'] ?? 0),
            ],
            'attempts_submitted' => (int) $entregas->entregadas,
            'average_pct'        => $entregas->promedio === null ? null : round((float) $entregas->promedio, 2),
            'pending_reviews'    => $pendientes,
            'upcoming_events'    => $proximos,
            'next_event'         => $siguiente,
            'resources'          => StudyResource::query()->visibleTo($user)->count(),
            'users'              => null,
            'attention'          => null,
        ];
    }

    /* =========================================================
     | Compartido
     ========================================================= */

    /**
     * Aulas con su recuento de alumnado. `student_count` ya es el recuento de
     * matrículas abiertas (RN-STU-012), así que no hace falta contar por aula.
     *
     * @return array<int,array<string,mixed>>
     */
    private function listaDeAulas($consulta): array
    {
        return $consulta
            ->orderByDesc('year')->orderBy('grade')->orderBy('section')
            ->get(['id', 'name', 'grade', 'section', 'year', 'student_count'])
            ->map(fn (Group $g) => [
                'id'       => $g->id,
                'name'     => $g->name,
                'grade'    => $g->grade,
                'section'  => $g->section,
                'year'     => (string) $g->year,
                'students' => (int) $g->student_count,
            ])->values()->all();
    }

    /**
     * Avisos próximos con la misma regla de visibilidad que /calendar-events.
     * El recuento y el primer aviso salen de UNA consulta (`COUNT(*) OVER ()`
     * cuenta todas las filas antes de que `LIMIT 1` se quede con la primera).
     *
     * @return array{0:int,1:array<string,mixed>|null}
     */
    private function avisosProximos(User $user): array
    {
        $siguiente = CalendarEvent::query()
            ->visibleTo($user)
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->selectRaw('calendar_events.id, calendar_events.title, calendar_events.start_at, COUNT(*) OVER () AS total')
            ->first();

        if ($siguiente === null) {
            return [0, null];
        }

        return [(int) $siguiente->total, [
            'id'       => $siguiente->id,
            'title'    => $siguiente->title,
            'start_at' => $siguiente->start_at?->toISOString(),
        ]];
    }
}
