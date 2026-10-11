<?php

namespace App\Services\Students;

use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\AI\AiTutorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentProgressService
{
    /**
     * Una sola sentencia: `INSERT … ON CONFLICT … RETURNING *` (O3).
     *
     * Antes era `updateOrCreate`, que son dos round-trips (SELECT y luego
     * INSERT o UPDATE) en la operación de pico. La restricción
     * `uniq_progress_student_subject (student_user_id, subject_id)` es la que
     * hace de clave del upsert.
     *
     * `institution_id` sale de la fila del estudiante en el propio INSERT …
     * SELECT, porque esto no pasa por el hook de `TenantScoped` y la columna es
     * NOT NULL. Con tenant ligado se acota además a él, igual que haría el scope.
     */
    public function upsertProgress(string $studentUserId, string $subjectId, float $percentage): StudentProgress
    {
        $tenant = app()->bound('tenant_id') ? app('tenant_id') : null;

        $fila = DB::selectOne(
            'INSERT INTO student_progress (id, institution_id, student_user_id, subject_id, mastery_percentage, updated_at)
             SELECT ?, s.institution_id, s.user_id, ?, ?, ?
             FROM students s
             WHERE s.user_id = ?' . ($tenant ? ' AND s.institution_id = ?' : '') . '
             ON CONFLICT (student_user_id, subject_id)
             DO UPDATE SET mastery_percentage = EXCLUDED.mastery_percentage, updated_at = EXCLUDED.updated_at
             RETURNING *',
            array_merge(
                [(string) Str::orderedUuid(), $subjectId, round($percentage, 2), now(), $studentUserId],
                $tenant ? [$tenant] : []
            )
        );

        if (!$fila) {
            throw new \RuntimeException("No existe el estudiante {$studentUserId} en esta institución.");
        }

        // El tutor arma su contexto con el progreso y los resultados de exámenes:
        // si esto cambió (entrega, revisión, reseteo), que lo vea ya y no al caducar.
        AiTutorService::olvidarContexto($studentUserId);

        return StudentProgress::hydrate([(array) $fila])->first();
    }

    /**
     * Recalcular desde intentos enviados (promedio de porcentajes)
     *
     * Respeta `reset_at`: si al estudiante se le reseteó el progreso en esta
     * materia (repitente), solo cuentan los intentos posteriores al corte. Los
     * anteriores siguen en la BD para auditoría, pero no arrastran la nota.
     */
    public function recalcFromAttempts(string $studentUserId, string $subjectId): StudentProgress
    {
        $tenant = app()->bound('tenant_id') ? app('tenant_id') : null;

        // UNA sentencia (antes eran tres viajes a la base): calcula el promedio de los intentos
        // entregados, guarda el progreso de la materia (`INSERT … ON CONFLICT`) y, si hay intentos,
        // pone al día la media general y los exámenes completados del alumno.
        //
        // Respeta `reset_at`: si al estudiante se le reseteó el progreso en esta materia
        // (repitente), solo cuentan los intentos posteriores al corte (subconsulta; sin fila o sin
        // corte, COALESCE da '-infinity' y no excluye nada). Los anteriores siguen en la BD para
        // auditoría, pero no arrastran la nota.
        //
        // La media general no puede leer lo que inserta esta misma sentencia, así que suma el valor
        // nuevo a los progresos de las OTRAS materias.
        $acotar = $tenant ? ' AND s.institution_id = ?' : '';
        $fila = DB::selectOne(
            "WITH agg AS (
                 SELECT COUNT(*) AS total, AVG((ea.score / ea.max_score) * 100) AS avg_pct
                   FROM exam_attempts ea
                   JOIN exams e ON e.id = ea.exam_id
                  WHERE ea.student_user_id = ? AND ea.submitted_at IS NOT NULL AND e.subject_id = ? AND ea.max_score > 0
                    AND ea.submitted_at > COALESCE((SELECT sp.reset_at FROM student_progress sp
                                                     WHERE sp.student_user_id = ea.student_user_id AND sp.subject_id = ?), '-infinity'::timestamp)
             ),
             nuevo AS (SELECT CASE WHEN total = 0 THEN 0 ELSE ROUND(avg_pct::numeric, 2) END AS pct FROM agg),
             prog AS (
                 INSERT INTO student_progress (id, institution_id, student_user_id, subject_id, mastery_percentage, updated_at)
                 SELECT ?, s.institution_id, s.user_id, ?, nuevo.pct, ?
                   FROM students s, nuevo
                  WHERE s.user_id = ?{$acotar}
                 ON CONFLICT (student_user_id, subject_id)
                 DO UPDATE SET mastery_percentage = EXCLUDED.mastery_percentage, updated_at = EXCLUDED.updated_at
                 RETURNING *
             ),
             est AS (
                 UPDATE students SET
                        overall_average = ROUND(COALESCE((SELECT AVG(m) FROM (
                                              SELECT sp.mastery_percentage AS m FROM student_progress sp
                                               WHERE sp.student_user_id = students.user_id AND sp.subject_id <> ?
                                              UNION ALL SELECT pct FROM nuevo) t), 0)::numeric, 2),
                        exams_completed_count = (SELECT COUNT(*) FROM exam_attempts ea2
                                                  WHERE ea2.student_user_id = students.user_id AND ea2.submitted_at IS NOT NULL),
                        last_activity_at = ?, updated_at = ?
                  WHERE user_id = ?{$this->acotarEst($tenant)} AND (SELECT total FROM agg) > 0
                  RETURNING 1
             )
             SELECT * FROM prog",
            array_merge(
                [$studentUserId, $subjectId, $subjectId, (string) Str::orderedUuid(), $subjectId, now(), $studentUserId],
                $tenant ? [$tenant] : [],
                [$subjectId, now(), now(), $studentUserId],
                $tenant ? [$tenant] : []
            )
        );

        if (!$fila) {
            throw new \RuntimeException("No existe el estudiante {$studentUserId} en esta institución.");
        }

        // El tutor arma su contexto con el progreso y los resultados de exámenes:
        // si esto cambió (entrega, revisión, reseteo), que lo vea ya y no al caducar.
        AiTutorService::olvidarContexto($studentUserId);

        return StudentProgress::hydrate([(array) $fila])->first();
    }

    private function acotarEst(?string $tenant): string
    {
        return $tenant ? ' AND institution_id = ?' : '';
    }

    /**
     * Media general y nº de exámenes del estudiante, en un solo UPDATE (O3).
     *
     * Antes eran tres round-trips —AVG, COUNT y el UPDATE—; las dos lecturas
     * van ahora como subconsultas del propio UPDATE. El `students` sigue
     * pasando por `TenantScoped` (y por el `updated_at` automático de Eloquent).
     */
    public function syncStudentStats(string $studentUserId): void
    {
        Student::where('user_id', $studentUserId)->update([
            'overall_average' => DB::raw(
                'ROUND(COALESCE((SELECT AVG(sp.mastery_percentage) FROM student_progress sp '
                . 'WHERE sp.student_user_id = students.user_id), 0)::numeric, 2)'
            ),
            'exams_completed_count' => DB::raw(
                '(SELECT COUNT(*) FROM exam_attempts ea '
                . 'WHERE ea.student_user_id = students.user_id AND ea.submitted_at IS NOT NULL)'
            ),
            'last_activity_at' => now(),
        ]);
    }
}