<?php

namespace App\Services\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;

/**
 * Reasignación masiva de estudiantes: cambio de grupo y de plan de materias.
 *
 * Todo corre dentro de una transacción: o se aplica el lote entero o nada.
 * Los ids que no correspondan a un estudiante de la institución no abortan la
 * operación, se devuelven en `skipped` para que el cliente los muestre.
 */
class BulkReassignmentService
{
    public const MODOS = ['replace', 'add', 'remove'];

    /**
     * Mueve estudiantes al grupo destino.
     *
     * - Cierra con `left_at` las membresías activas en OTROS grupos (conserva
     *   el historial, igual que GroupController::removeStudents).
     * - Da de alta en el destino; si el estudiante ya estuvo ahí y se fue,
     *   reabre la fila con un `joined_at` nuevo.
     * - Sincroniza students.grade/section/group_code con el grupo destino.
     * - Recalcula `student_count` de TODOS los grupos afectados, no solo del
     *   destino: los de origen también perdieron gente.
     */
    public function reassignGroup(
        string $institutionId,
        array $studentUserIds,
        Group $destino,
        bool $syncStudentFields = true
    ): array {
        return DB::transaction(function () use ($institutionId, $studentUserIds, $destino, $syncStudentFields) {
            $pedidos = array_values(array_unique($studentUserIds));

            // Solo estudiantes reales del tenant (Student está TenantScoped).
            $validos = Student::query()
                ->whereIn('user_id', $pedidos)
                ->pluck('user_id')
                ->all();

            $omitidos = array_values(array_diff($pedidos, $validos));

            if (empty($validos)) {
                return $this->resumenGrupo($destino, $pedidos, $omitidos, 0, 0, []);
            }

            $ahora = now();

            // Quiénes ya están activos en el destino: no se tocan, para no
            // pisarles el joined_at con un "movimiento" que no ocurrió.
            $yaEnDestino = DB::table('group_students')
                ->where('group_id', $destino->id)
                ->whereIn('student_user_id', $validos)
                ->whereNull('left_at')
                ->pluck('student_user_id')
                ->all();

            $aMover = array_values(array_diff($validos, $yaEnDestino));

            // Grupos de origen, capturados ANTES de cerrar las membresías.
            $gruposOrigen = DB::table('group_students')
                ->whereIn('student_user_id', $aMover)
                ->whereNull('left_at')
                ->where('group_id', '!=', $destino->id)
                ->distinct()
                ->pluck('group_id')
                ->all();

            $cerradas = 0;
            if (!empty($aMover)) {
                $cerradas = DB::table('group_students')
                    ->whereIn('student_user_id', $aMover)
                    ->whereNull('left_at')
                    ->where('group_id', '!=', $destino->id)
                    ->update(['left_at' => $ahora]);

                // INSERT ... ON CONFLICT (group_id, student_user_id): un solo
                // roundtrip. Reabre filas cerradas con joined_at nuevo, porque
                // volver a un grupo es un ingreso nuevo, no la misma estadía.
                DB::table('group_students')->upsert(
                    array_map(fn ($id) => [
                        'institution_id'  => $institutionId,
                        'group_id'        => $destino->id,
                        'student_user_id' => $id,
                        'joined_at'       => $ahora,
                        'left_at'         => null,
                    ], $aMover),
                    ['group_id', 'student_user_id'],
                    ['joined_at', 'left_at']
                );
            }

            $sincronizados = 0;
            if ($syncStudentFields && !empty($aMover)) {
                $sincronizados = Student::query()
                    ->whereIn('user_id', $aMover)
                    ->update([
                        'grade'      => $destino->grade,
                        'section'    => $destino->section,
                        'group_code' => $destino->group_code,
                        // `year` es lo que distingue "7-A 2026" de "7-A 2027":
                        // sin esto, un repitente que pasa de 7A-2026 a 7A-2027
                        // quedaría con el año viejo pese a haber sido reasignado.
                        'year'       => $destino->year,
                        'updated_at' => $ahora,
                    ]);
            }

            // El destino siempre se recuenta; los de origen solo si los hubo.
            $recontados = array_values(array_unique([...$gruposOrigen, $destino->id]));
            $this->recontarGrupos($recontados);

            return $this->resumenGrupo(
                $destino->fresh(),
                $pedidos,
                $omitidos,
                count($aMover),
                count($yaEnDestino),
                $recontados,
                $cerradas,
                $sincronizados
            );
        });
    }

    /**
     * Reasigna el plan de materias de un lote de estudiantes.
     *
     * - replace: el estudiante queda EXACTAMENTE con las materias indicadas.
     * - add:     añade las indicadas, no quita nada.
     * - remove:  desinscribe las indicadas, no toca el resto.
     */
    public function reassignSubjects(
        string $institutionId,
        array $studentUserIds,
        array $subjectIds,
        string $mode
    ): array {
        return DB::transaction(function () use ($institutionId, $studentUserIds, $subjectIds, $mode) {
            $pedidos = array_values(array_unique($studentUserIds));
            $materias = array_values(array_unique($subjectIds));

            $validos = Student::query()
                ->whereIn('user_id', $pedidos)
                ->pluck('user_id')
                ->all();

            $omitidos = array_values(array_diff($pedidos, $validos));

            if (empty($validos)) {
                return $this->resumenMaterias($mode, $pedidos, $omitidos, $materias, 0, 0);
            }

            $inscritas = 0;
            $desinscritas = 0;

            if ($mode === 'remove') {
                $desinscritas = DB::table('student_subjects')
                    ->whereIn('student_user_id', $validos)
                    ->whereIn('subject_id', $materias)
                    ->delete();

                return $this->resumenMaterias($mode, $pedidos, $omitidos, $materias, 0, $desinscritas);
            }

            if ($mode === 'replace') {
                // Quita lo que sobra respecto del plan indicado. Con el plan
                // vacío esto deja al estudiante sin materias, que es lo que
                // "replace con lista vacía" significa.
                $desinscritas = DB::table('student_subjects')
                    ->whereIn('student_user_id', $validos)
                    ->when(!empty($materias), fn ($q) => $q->whereNotIn('subject_id', $materias))
                    ->delete();
            }

            if (!empty($materias)) {
                $ahora = now();

                $filas = [];
                foreach ($validos as $studentId) {
                    foreach ($materias as $subjectId) {
                        $filas[] = [
                            'institution_id'  => $institutionId,
                            'student_user_id' => $studentId,
                            'subject_id'      => $subjectId,
                            'enrolled_at'     => $ahora,
                            'created_at'      => $ahora,
                            'updated_at'      => $ahora,
                        ];
                    }
                }

                // insertOrIgnore se apoya en UNIQUE(student_user_id, subject_id):
                // las inscripciones que ya existían se saltan sin error y sin
                // pisarles el enrolled_at original.
                $inscritas = DB::table('student_subjects')->insertOrIgnore($filas);
            }

            return $this->resumenMaterias($mode, $pedidos, $omitidos, $materias, $inscritas, $desinscritas);
        });
    }

    /**
     * Resetea el progreso de un lote de estudiantes (repitentes).
     *
     * Pone `mastery_percentage` a 0 y marca `reset_at`, que es lo que hace el
     * reseteo DURADERO: `StudentProgressService::recalcFromAttempts` ignora los
     * intentos anteriores al corte, así que el próximo examen no restaura la
     * nota vieja. Los intentos y respuestas NO se borran — el historial
     * académico se conserva íntegro, solo deja de contar para el dominio actual.
     *
     * Sin `subjectIds` resetea todas las materias del estudiante.
     */
    public function resetProgress(array $studentUserIds, array $subjectIds = []): array
    {
        return DB::transaction(function () use ($studentUserIds, $subjectIds) {
            $pedidos = array_values(array_unique($studentUserIds));

            $validos = Student::query()
                ->whereIn('user_id', $pedidos)
                ->pluck('user_id')
                ->all();

            $omitidos = array_values(array_diff($pedidos, $validos));

            if (empty($validos)) {
                return [
                    'requested'         => count($pedidos),
                    'affected_students' => 0,
                    'skipped'           => $omitidos,
                    'progress_reset'    => 0,
                    'subjects'          => count($subjectIds),
                ];
            }

            $ahora = now();

            $reseteadas = DB::table('student_progress')
                ->whereIn('student_user_id', $validos)
                ->when(!empty($subjectIds), fn ($q) => $q->whereIn('subject_id', $subjectIds))
                ->update([
                    'mastery_percentage' => 0,
                    'reset_at'           => $ahora,
                    'updated_at'         => $ahora,
                ]);

            // `overall_average` deriva del progreso, así que hay que recomputarlo.
            // No se toca `last_activity_at` (esto es una acción del admin, no del
            // estudiante) ni `exams_completed_count`, que es un total histórico.
            DB::table('students')
                ->whereIn('user_id', $validos)
                ->update([
                    'overall_average' => DB::raw('COALESCE((
                        SELECT ROUND(AVG(sp.mastery_percentage), 2)
                        FROM student_progress sp
                        WHERE sp.student_user_id = students.user_id
                    ), 0)'),
                    'updated_at' => $ahora,
                ]);

            return [
                'requested'         => count($pedidos),
                'affected_students' => count($validos),
                'skipped'           => $omitidos,
                'progress_reset'    => $reseteadas,
                'subjects'          => count($subjectIds),
            ];
        });
    }

    /**
     * Valida que las materias existan dentro del tenant.
     * Devuelve los ids que NO pertenecen a la institución.
     */
    public function subjectsFueraDelTenant(array $subjectIds): array
    {
        if (empty($subjectIds)) {
            return [];
        }

        $existentes = Subject::query()
            ->whereIn('id', $subjectIds)
            ->pluck('id')
            ->all();

        return array_values(array_diff(array_unique($subjectIds), $existentes));
    }

    /** Recalcula student_count (RN-STU-012) de varios grupos en una query. */
    private function recontarGrupos(array $groupIds): void
    {
        if (empty($groupIds)) {
            return;
        }

        DB::table('groups')
            ->whereIn('id', $groupIds)
            ->update([
                'student_count' => DB::raw('(
                    SELECT COUNT(*) FROM group_students gs
                    WHERE gs.group_id = groups.id AND gs.left_at IS NULL
                )'),
                'updated_at' => now(),
            ]);
    }

    private function resumenGrupo(
        Group $destino,
        array $pedidos,
        array $omitidos,
        int $movidos,
        int $yaEstaban,
        array $recontados,
        int $membresiasCerradas = 0,
        int $camposSincronizados = 0
    ): array {
        return [
            'target_group' => [
                'id'            => $destino->id,
                'name'          => $destino->name,
                'grade'         => $destino->grade,
                'section'       => $destino->section,
                'student_count' => $destino->student_count,
            ],
            'requested'             => count($pedidos),
            'moved'                 => $movidos,
            'already_in_group'      => $yaEstaban,
            'skipped'               => $omitidos,
            'memberships_closed'    => $membresiasCerradas,
            'student_fields_synced' => $camposSincronizados,
            'groups_recounted'      => $recontados,
        ];
    }

    private function resumenMaterias(
        string $mode,
        array $pedidos,
        array $omitidos,
        array $materias,
        int $inscritas,
        int $desinscritas
    ): array {
        return [
            'mode'         => $mode,
            'requested'    => count($pedidos),
            'affected'     => count($pedidos) - count($omitidos),
            'skipped'      => $omitidos,
            'subjects'     => count($materias),
            'enrolled'     => $inscritas,
            'unenrolled'   => $desinscritas,
        ];
    }
}
