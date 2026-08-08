<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\AI\AiRecommendation;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiRecommendationController extends Controller
{
    use AcotaAlDocente;

    /**
     * Listar recomendaciones
     * - Admin/Teacher: puede filtrar por student_user_id
     * - Student: solo ve las propias
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'student_user_id'   => ['nullable', 'uuid'],
            'subject_id'        => ['nullable', 'uuid'],
            'exam_id'           => ['nullable', 'uuid'],
            'recommendation_type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
        ]);

        $query = AiRecommendation::query()
            ->with(['student.user', 'subject', 'exam'])
            ->orderByDesc('created_at');

        // 👩‍🎓 Estudiante: solo sus recomendaciones
        if ($user->user_type->value === 'student') {
            $query->where('student_user_id', $user->id);
        }

        // 👨‍🏫 Docente: solo sus estudiantes (grupo asignado) y solo sus materias.
        //
        // `show()` ya aplicaba una regla y `index()` no: un docente podía listar
        // el `recommendation_text` de CUALQUIER alumno de la institución. Las
        // dos vías tienen que decir lo mismo, y la restrictiva es la correcta —
        // son textos generados por IA sobre el desempeño de menores.
        //
        // La regla ya no es «exámenes que yo creé» sino la asignación del admin,
        // que no se puede ampliar uno mismo. De paso deja de perder las
        // recomendaciones sin `exam_id`, que antes no veía nadie más que admin.
        if ($this->esDocente($user)) {
            $this->acotarAEstudiantesDelDocente($query, $user);
            $query->whereIn('subject_id', $this->materiasDelDocente($user->id));
        }

        // 👨‍🏫 Admin / Teacher
        if (!empty($data['student_user_id'])) {
            $query->where('student_user_id', $data['student_user_id']);
        }

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        if (!empty($data['exam_id'])) {
            $query->where('exam_id', $data['exam_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('recommendation_type', $data['recommendation_type']);
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Ver una recomendación específica
     */
    public function show(AiRecommendation $aiRecommendation, Request $request)
    {
        $user = $request->user();

        // Estudiante: solo puede ver las suyas
        if ($user->user_type->value === 'student' && $aiRecommendation->student_user_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Docente: el alumno tiene que ser de un grupo suyo y la recomendación,
        // de una materia que imparte. Misma regla que `index()`.
        if ($this->esDocente($user)) {
            $alcanzaAlumno  = $this->docenteAlcanzaEstudiante($user, $aiRecommendation->student_user_id);
            $imparteMateria = $this->materiasDelDocente($user->id)
                ->where('subject_id', $aiRecommendation->subject_id)
                ->exists();

            if (!$alcanzaAlumno || !$imparteMateria) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        return response()->json([
            'data' => $aiRecommendation->load(['student.user', 'subject', 'exam']),
        ]);
    }

    /**
     * Recomendaciones del estudiante autenticado (atajo)
     */
    public function myRecommendations(Request $request)
    {
        $user = $request->user();

        // Confirmar que tenga perfil de estudiante
        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        $data = $request->validate([
            'subject_id'          => ['nullable', 'uuid'],
            'recommendation_type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
        ]);

        $query = AiRecommendation::query()
            ->with(['subject', 'exam'])
            ->where('student_user_id', $user->id)
            ->orderByDesc('created_at');

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('recommendation_type', $data['recommendation_type']);
        }

        return response()->json([
            'data' => $query->paginate(15),
        ]);
    }
}
