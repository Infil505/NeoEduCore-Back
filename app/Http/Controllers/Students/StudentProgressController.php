<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Support\RelacionesEnLinea;
use App\Models\Academic\Subject;
use Illuminate\Http\Request;

class StudentProgressController extends Controller
{
    use AcotaAlDocente;

    /**
     * Listar progreso
     * - Admin/Teacher: puede filtrar por student_user_id y subject_id
     * - Student: solo ve su propio progreso
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'student_user_id' => ['nullable', 'uuid'],
            'subject_id'      => ['nullable', 'uuid'],
        ]);

        // Alumno y su usuario unidos en la MISMA consulta (`RelacionesEnLinea`) y la materia del
        // catálogo en caché: antes eran cuatro viajes a la base (~0,4 s cada uno).
        $query = RelacionesEnLinea::unir(StudentProgress::query(), [
            'student'      => ['students', 'student_user_id', ['user_id', 'student_code', 'grade', 'section'], null, 'user_id'],
            'student_user' => ['users', 'student_user_id', ['id', 'full_name']],
        ])->orderByDesc('student_progress.updated_at');

        // Estudiante: solo lo propio
        if ($user->user_type->value === 'student') {
            $query->where('student_progress.student_user_id', $user->id);
        } else {
            if (!empty($data['student_user_id'])) {
                $query->where('student_progress.student_user_id', $data['student_user_id']);
            }

            // Docente: solo los estudiantes de los grupos que tiene asignados.
            // Sin esto, listar sin filtro devolvía el progreso de toda la
            // institución.
            $this->acotarAEstudiantesDelDocente($query, $user, 'student_progress.student_user_id');
        }

        if (!empty($data['subject_id'])) {
            $query->where('student_progress.subject_id', $data['subject_id']);
        }

        $paginator = $this->paginar($query, $request);

        RelacionesEnLinea::hidratar($paginator->getCollection(), [
            'student'      => Student::class,
            'student_user' => User::class,
        ]);
        $materias = $this->materiasDelCentro($user->institution_id);
        foreach ($paginator->getCollection() as $progreso) {
            $progreso->student?->setRelation('user', $progreso->getRelation('student_user'));
            $progreso->unsetRelation('student_user');
            $materia = $materias->get($progreso->subject_id);
            // Solo `id` y `name`, como antes (`subject:id,name`); se clona para no tocar la
            // copia compartida del catálogo.
            $progreso->setRelation('subject', $materia ? (clone $materia)->setVisible(['id', 'name']) : null);
        }

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Ver progreso del estudiante autenticado (atajo)
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        $progress = StudentProgress::query()
            ->with('subject')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $progress,
        ]);
    }

    /**
     * Recalcular progreso (manual o por acción del docente)
     * body:
     * {
     *   "student_user_id": "uuid",
     *   "subject_id": "uuid",
     *   "mastery_percentage": 0-100
     * }
     *
     * Nota: en un sistema real, esto se calcula desde intentos y resultados.
     * Aquí dejamos endpoint para actualizar/recalcular según tu lógica.
     */
    public function upsert(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'student_user_id'    => ['required', 'uuid'],
            'subject_id'         => ['required', 'uuid'],
            'mastery_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // Verificar que existan (y pertenezcan al tenant) vía scopes
        Student::where('user_id', $data['student_user_id'])->firstOrFail();
        Subject::where('id', $data['subject_id'])->firstOrFail();

        // Docente: solo estudiantes de los grupos que tiene asignados.
        // Antes esto se derivaba de la autoría del examen, lo que permitía
        // ampliarse el alcance creando un borrador dirigido a cualquier grupo.
        if ($this->esDocente($user) && !$this->docenteAlcanzaEstudiante($user, $data['student_user_id'])) {
            return $this->noAutorizadoPorAsignacion();
        }

        // ...y solo en las materias que imparte a ese alumno.
        if ($this->esDocente($user)
            && !$this->docenteImparteMateriaAEstudiante($user, $data['student_user_id'], $data['subject_id'])) {
            return $this->noAutorizadoPorMateria();
        }

        $progress = StudentProgress::updateOrCreate(
            [
                'student_user_id' => $data['student_user_id'],
                'subject_id' => $data['subject_id'],
            ],
            [
                'mastery_percentage' => round((float)$data['mastery_percentage'], 2),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'data' => $progress->load(['student.user', 'subject']),
        ], 201);
    }

    /**
     * Recalcular progreso automáticamente desde intentos (opcional)
     * - Si luego querés, aquí se calcula usando ExamAttempt y StudentAnswer.
     */
    public function recalcFromAttempts(Request $request)
    {
        $data = $request->validate([
            'student_user_id' => ['nullable', 'uuid'],
            'subject_id'      => ['nullable', 'uuid'],
        ]);

        $query = StudentProgress::query();

        if (!empty($data['student_user_id'])) {
            $query->where('student_user_id', $data['student_user_id']);
        }

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        // Sin filtros, esto tocaba el progreso de toda la institución. El
        // docente queda acotado a sus grupos asignados.
        $this->acotarAEstudiantesDelDocente($query, $request->user());

        // ...y a las materias que imparte (igual que en `upsert`).
        if ($this->esDocente($request->user())) {
            $query->whereIn('subject_id', $this->materiasDelDocente($request->user()->id));
        }

        // Una sola query UPDATE en lugar de N toques individuales
        $count = $query->update(['updated_at' => now()]);

        return response()->json([
            'recalculated' => $count,
        ]);
    }
}
