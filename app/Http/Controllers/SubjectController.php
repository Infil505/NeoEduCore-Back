<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * Listar materias (tenant scoped)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        $query = Subject::query()
            ->where('institution_id', $user->institution_id)
            ->orderBy('name');

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Crear materia (tenant scoped)
     * - institution_id sale del usuario autenticado
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        // Evitar duplicados por institución (si en DB tienes UNIQUE(institution_id, name))
        $name = trim($data['name']);

        $subject = Subject::create([
            'institution_id' => $user->institution_id, // ✅ CLAVE
            'name' => $name,
        ]);

        return response()->json([
            'data' => $subject,
        ], 201);
    }

    /**
     * Ver materia (tenant scoped)
     */
    public function show(Request $request, Subject $subject)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        if ($subject->institution_id !== $user->institution_id) {
            return response()->json([
                'message' => 'No autorizado para ver esta materia.',
            ], 403);
        }

        return response()->json([
            'data' => $subject,
        ]);
    }

    /**
     * Actualizar materia (tenant scoped)
     */
    public function update(Request $request, Subject $subject)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        if ($subject->institution_id !== $user->institution_id) {
            return response()->json([
                'message' => 'No autorizado para modificar esta materia.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $subject->name = trim($data['name']);
        $subject->save();

        return response()->json([
            'data' => $subject->fresh(),
        ]);
    }

    /**
     * Eliminar materia (tenant scoped)
     * - recomendado: si tiene exámenes asociados, bloquear
     */
    public function destroy(Request $request, Subject $subject)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        if ($subject->institution_id !== $user->institution_id) {
            return response()->json([
                'message' => 'No autorizado para eliminar esta materia.',
            ], 403);
        }

        if ($subject->exams()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar una materia con exámenes asociados',
            ], 409);
        }

        $subject->delete();

        return response()->json([
            'message' => 'Materia eliminada',
        ]);
    }
}