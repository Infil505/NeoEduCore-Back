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
        $query = Subject::query()->orderBy('name');

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Crear materia
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $subject = Subject::create([
            'name' => trim($data['name']),
        ]);

        return response()->json([
            'data' => $subject,
        ], 201);
    }

    /**
     * Ver materia
     */
    public function show(Subject $subject)
    {
        return response()->json([
            'data' => $subject->load('institution'),
        ]);
    }

    /**
     * Actualizar materia
     */
    public function update(Request $request, Subject $subject)
    {
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
     * Eliminar materia
     * - recomendado: si tiene exámenes asociados, bloquear
     */
    public function destroy(Subject $subject)
    {
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