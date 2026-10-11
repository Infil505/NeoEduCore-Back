<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\AI\AiRecommendation;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Support\RelacionesEnLinea;
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
            // Solo el personal: a quién iba dirigida (el estudiante nunca ve las del docente).
            'audience'          => ['nullable', Rule::in([AiRecommendation::PARA_ESTUDIANTE, AiRecommendation::PARA_DOCENTE])],
        ]);

        // Alumno, su usuario y el examen unidos en la MISMA consulta (`RelacionesEnLinea`) y
        // la materia del catálogo en caché: antes eran cinco viajes a la base (~0,4 s cada
        // uno). Las columnas van con el nombre de la tabla porque `exams` también tiene
        // `subject_id`.
        $query = RelacionesEnLinea::unir(AiRecommendation::query(), [
            'student'      => ['students', 'student_user_id', ['user_id', 'student_code', 'grade', 'section'], null, 'user_id'],
            'student_user' => ['users', 'student_user_id', ['id', 'full_name']],
            'exam'         => ['exams', 'exam_id', ['id', 'title']],
        ])->orderByDesc('ai_recommendations.created_at');

        // 👩‍🎓 Estudiante: solo sus recomendaciones
        if ($user->user_type->value === 'student') {
            $query->where('ai_recommendations.student_user_id', $user->id)
                ->where('ai_recommendations.audience', AiRecommendation::PARA_ESTUDIANTE);
        } elseif (!empty($data['audience'])) {
            $query->where('ai_recommendations.audience', $data['audience']);
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
            $this->acotarAEstudiantesDelDocente($query, $user, 'ai_recommendations.student_user_id');
            $query->whereIn('ai_recommendations.subject_id', $this->materiasDelDocente($user->id));
        }

        // 👨‍🏫 Admin / Teacher
        if (!empty($data['student_user_id'])) {
            $query->where('ai_recommendations.student_user_id', $data['student_user_id']);
        }

        if (!empty($data['subject_id'])) {
            $query->where('ai_recommendations.subject_id', $data['subject_id']);
        }

        if (!empty($data['exam_id'])) {
            $query->where('ai_recommendations.exam_id', $data['exam_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('ai_recommendations.recommendation_type', $data['recommendation_type']);
        }

        $paginator = $this->paginar($query, $request);
        $this->hidratarRelaciones($paginator->getCollection(), $user->institution_id);

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Convierte las columnas unidas en las relaciones `student` (con su `user`), `exam` y
     * `subject` (esta del catálogo en caché del centro), con la misma forma de siempre.
     *
     * @param  iterable<AiRecommendation>  $recomendaciones
     */
    private function hidratarRelaciones(iterable $recomendaciones, string $centro): void
    {
        RelacionesEnLinea::hidratar($recomendaciones, [
            'student'      => Student::class,
            'student_user' => User::class,
            'exam'         => Exam::class,
        ]);

        $materias = $this->materiasDelCentro($centro);

        foreach ($recomendaciones as $recomendacion) {
            $recomendacion->student?->setRelation('user', $recomendacion->getRelation('student_user'));
            $recomendacion->unsetRelation('student_user');
            $materia = $materias->get($recomendacion->subject_id);
            // Solo `id` y `name`, como antes (`subject:id,name`); se clona para no tocar la
            // copia compartida del catálogo.
            $recomendacion->setRelation('subject', $materia ? (clone $materia)->setVisible(['id', 'name']) : null);
        }
    }

    /**
     * Ver una recomendación específica
     */
    public function show(string $aiRecommendation, Request $request)
    {
        $user = $request->user();

        // La recomendación con alumno, su usuario y examen unidos en una sola consulta (antes:
        // el binding y tres relaciones más). La materia sale del catálogo en caché.
        $aiRecommendation = RelacionesEnLinea::unir(AiRecommendation::query(), [
            'student'      => ['students', 'student_user_id', Student::COLUMNAS, null, 'user_id'],
            'student_user' => ['users', 'student_user_id', Student::COLUMNAS_USUARIO],
            'exam'         => ['exams', 'exam_id', Exam::COLUMNAS],
        ])->where('ai_recommendations.id', $aiRecommendation)->first();

        if ($aiRecommendation === null) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        // Estudiante: solo puede ver las suyas, y no el consejo que se escribió para el docente
        if ($user->user_type->value === 'student'
            && ($aiRecommendation->student_user_id !== $user->id || $aiRecommendation->audience !== AiRecommendation::PARA_ESTUDIANTE)) {
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

        $this->hidratarRelaciones([$aiRecommendation], $user->institution_id);
        // Aquí `subject` va completo, como antes (`load('subject')`).
        $aiRecommendation->setRelation('subject', $this->materiasDelCentro($user->institution_id)->get($aiRecommendation->subject_id));

        return response()->json([
            'data' => $aiRecommendation,
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

        // El examen unido en la misma consulta (`RelacionesEnLinea`) y la materia del catálogo
        // en caché: antes eran dos consultas de relaciones más.
        $query = RelacionesEnLinea::unir(AiRecommendation::query(), [
            'exam' => ['exams', 'exam_id', Exam::COLUMNAS],
        ])
            ->where('ai_recommendations.student_user_id', $user->id)
            ->where('ai_recommendations.audience', AiRecommendation::PARA_ESTUDIANTE)
            ->orderByDesc('ai_recommendations.created_at');

        if (!empty($data['subject_id'])) {
            $query->where('ai_recommendations.subject_id', $data['subject_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('ai_recommendations.recommendation_type', $data['recommendation_type']);
        }

        $paginator = $this->paginar($query, $request);
        RelacionesEnLinea::hidratar($paginator->getCollection(), ['exam' => Exam::class]);
        $materias = $this->materiasDelCentro($user->institution_id);
        $paginator->getCollection()->each(fn ($r) => $r->setRelation('subject', $materias->get($r->subject_id)));

        return response()->json([
            'data' => $paginator,
        ]);
    }
}
