<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Services\AI\AiAsistenteDocenteService;
use Illuminate\Http\Request;

class AiAsistenteDocenteController extends Controller
{
    use AcotaAlDocente;

    /**
     * POST /api/ai/teacher/chat — conversar con la IA sobre los resultados de la clase.
     *
     * Sin `exam_id` el asistente ve los últimos exámenes del docente (o del centro,
     * si quien pregunta es administrador) y los temas flojos de toda la clase; con
     * `exam_id`, ese examen entra con más detalle. El docente solo puede enfocar
     * un examen que creó, igual que el informe de análisis.
     */
    public function chat(Request $request, AiAsistenteDocenteService $asistente)
    {
        $user = $request->user();

        $data = $request->validate([
            'message'           => ['required', 'string', 'min:1', 'max:2000'],
            'exam_id'           => ['nullable', 'uuid'],
            'history'           => ['nullable', 'array', 'max:20'],
            'history.*.role'    => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:3000'],
        ]);

        $foco = null;

        if (!empty($data['exam_id'])) {
            // TenantScoped: un examen de otro centro es un 404.
            $foco = Exam::with('subject:id,name')->findOrFail($data['exam_id']);

            if ($this->esDocente($user) && $foco->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        $respuesta = $asistente->responder(
            $user->institution_id,
            $this->esDocente($user) ? $user->id : null,
            $data['message'],
            $foco,
            $data['history'] ?? []
        );

        return response()->json(['data' => $respuesta]);
    }
}
