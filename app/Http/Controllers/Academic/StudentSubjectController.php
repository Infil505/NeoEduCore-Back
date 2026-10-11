<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use App\Models\Academic\StudentSubject;
use App\Models\Students\Student;
use App\Rules\MateriaDelCentro;
use App\Services\Students\StudentSubjectsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentSubjectController extends Controller
{
    use AcotaAlDocente;

    /**
     * GET /api/students/{student_user_id}/subjects
     * Lista las materias en las que está inscrito un estudiante (admin/teacher).
     */
    public function index(string $studentUserId, Request $request, StudentSubjectsService $materias)
    {
        $student = Student::where('user_id', $studentUserId)->firstOrFail();

        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $studentUserId)) {
            return $this->noAutorizadoPorAsignacion();
        }

        // Heredadas de su sección + inscritas individualmente (ver el servicio).
        return response()->json(['data' => $materias->materias($student->user_id, $student->institution_id)]);
    }

    /**
     * GET /api/students/me/subjects
     * Materias propias del estudiante autenticado.
     */
    public function mySubjects(Request $request, StudentSubjectsService $materias)
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => $materias->materias($student->user_id, $student->institution_id)]);
    }

    /**
     * GET /api/students/{student_user_id}/assignable-subjects
     *
     * Materias que QUIEN PREGUNTA puede inscribirle a este estudiante.
     * Para un docente, no es "todo el catálogo" ni siquiera "mis materias":
     * es la materia que el admin ya le asignó a él en algún grupo donde este
     * estudiante esté activo — lo mismo que exige `enroll()` para aceptar la
     * inscripción, expuesto aquí para que el frontend no ofrezca una opción
     * que el backend va a rechazar. Para admin, el catálogo completo.
     */
    public function assignableByMe(string $studentUserId, Request $request)
    {
        $user = $request->user();
        $student = Student::where('user_id', $studentUserId)->firstOrFail();

        if ($this->esDocente($user) && !$this->docenteAlcanzaEstudiante($user, $studentUserId)) {
            return $this->noAutorizadoPorAsignacion();
        }

        $query = Subject::query()->orderBy('name');

        if ($this->esDocente($user)) {
            $query->whereIn('id', $this->materiasAsignablesAEstudiante($user->id, $studentUserId));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/students/{student_user_id}/subjects
     * Inscribir uno o varios estudiantes a una materia (admin/teacher).
     * body: { "subject_id": "uuid" }
     */
    public function enroll(string $studentUserId, Request $request)
    {
        $user = $request->user();

        // La materia, contra el catálogo en caché (sin consulta a la base).
        $data = $request->validate([
            'subject_id' => ['required', 'uuid', new MateriaDelCentro($user->institution_id)],
        ]);

        // El alumno y, si inscribe un docente, si lo alcanza: UNA consulta.
        $student = $this->alumnoConUsuarioVisiblePor($user, $studentUserId) ?? abort(404);
        $subject = $this->materiasDelCentro($user->institution_id)->get($data['subject_id']) ?? abort(404);

        if ($this->esDocente($user)) {
            if ($this->noAlcanzaAlAlumno($user, $student)) {
                return $this->noAutorizadoPorAsignacion();
            }

            // No basta con que el estudiante sea suyo: la materia concreta
            // tiene que ser la que el admin le asignó para el grupo de este
            // estudiante. Antes un docente podía inscribir a su alumno en
            // cualquier materia del catálogo, no solo en la que imparte.
            if (!$this->docenteImparteMateriaAEstudiante($user, $studentUserId, $subject->id)) {
                return response()->json([
                    'message' => 'No autorizado: no impartes esta materia al grupo de este estudiante.',
                ], 403);
            }
        }

        // Comprobar y escribir en UNA sentencia: si ya estaba inscrito no inserta nada.
        $insertadas = DB::affectingStatement(
            'INSERT INTO student_subjects (id, institution_id, student_user_id, subject_id, enrolled_at, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?
              WHERE NOT EXISTS (SELECT 1 FROM student_subjects
                                 WHERE student_user_id = ? AND subject_id = ? AND institution_id = ?)',
            [
                (string) \Illuminate\Support\Str::orderedUuid(), $student->institution_id, $studentUserId, $subject->id,
                now(), now(), now(),
                $studentUserId, $subject->id, $student->institution_id,
            ]
        );

        if ($insertadas === 0) {
            return response()->json(['message' => 'El estudiante ya está inscrito en esta materia'], 409);
        }

        return response()->json([
            'message' => 'Inscripción registrada',
            'data'    => [
                'student_user_id' => $studentUserId,
                'subject_id'      => $subject->id,
                'subject_name'    => $subject->name,
            ],
        ], 201);
    }

    /**
     * DELETE /api/students/{student_user_id}/subjects/{subject}
     * Desinscribir a un estudiante de una materia (admin/teacher).
     */
    public function unenroll(string $studentUserId, string $subjectId, Request $request)
    {
        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $studentUserId)) {
            return $this->noAutorizadoPorAsignacion();
        }

        if ($this->esDocente($request->user())
            && !$this->docenteImparteMateriaAEstudiante($request->user(), $studentUserId, $subjectId)) {
            return $this->noAutorizadoPorMateria();
        }

        $deleted = StudentSubject::where('student_user_id', $studentUserId)
            ->where('subject_id', $subjectId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        return response()->json(['message' => 'Estudiante desinscrito de la materia']);
    }
}
