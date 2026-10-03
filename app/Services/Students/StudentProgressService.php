<?php

namespace App\Services\Students;

use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
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
        // JOIN en lugar de whereHas + get() evita cargar registros en RAM para calcular AVG.
        //
        // El corte de `reset_at` va como subconsulta en vez de leerse antes en
        // una query aparte (O3). Sin fila de progreso, o sin corte, COALESCE da
        // '-infinity' y el filtro no excluye nada: mismo resultado que antes.
        $result = ExamAttempt::query()
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->where('exam_attempts.student_user_id', $studentUserId)
            ->whereNotNull('exam_attempts.submitted_at')
            ->where('exams.subject_id', $subjectId)
            ->where('exam_attempts.max_score', '>', 0)
            ->whereRaw(
                "exam_attempts.submitted_at > COALESCE((
                    SELECT sp.reset_at FROM student_progress sp
                    WHERE sp.student_user_id = exam_attempts.student_user_id AND sp.subject_id = ?
                ), '-infinity'::timestamp)",
                [$subjectId]
            )
            ->selectRaw('COUNT(*) as total, AVG((exam_attempts.score / exam_attempts.max_score) * 100) as avg_pct')
            ->first();

        if (!$result || (int) $result->total === 0) {
            return $this->upsertProgress($studentUserId, $subjectId, 0);
        }

        $progress = $this->upsertProgress($studentUserId, $subjectId, (float) $result->avg_pct);

        $this->syncStudentStats($studentUserId);

        return $progress;
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