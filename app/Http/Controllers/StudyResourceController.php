<?php

namespace App\Http\Controllers;

use App\Enums\ResourceType;
use App\Models\StudyResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudyResourceController extends Controller
{
    /**
     * Listar recursos (con filtros)
     */
    public function index(Request $request)
    {
        $query = StudyResource::query()
            ->with('creator')
            ->orderByDesc('created_at');

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->string('resource_type')->toString());
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->string('difficulty')->toString());
        }

        if ($request->filled('grade')) {
            $grade = (int) $request->input('grade');
            $query->where(function ($q) use ($grade) {
                $q->whereNull('grade_min')->orWhere('grade_min', '<=', $grade);
            })->where(function ($q) use ($grade) {
                $q->whereNull('grade_max')->orWhere('grade_max', '>=', $grade);
            });
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Crear recurso
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'resource_type' => ['required', Rule::in(array_map(
                fn($e) => $e->value,
                ResourceType::cases()
            ))],

            'url' => ['required', 'url', 'max:500'],

            'estimated_duration' => ['nullable', 'integer', 'between:1,999'],
            'difficulty' => ['nullable', Rule::in(['basic', 'intermediate', 'advanced'])],

            'grade_min' => ['nullable', 'integer', 'between:1,12'],
            'grade_max' => ['nullable', 'integer', 'between:1,12', 'gte:grade_min'],

            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $user = $request->user();

        $resource = StudyResource::create([
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'resource_type' => $data['resource_type'],
            'url' => $data['url'],

            'estimated_duration' => $data['estimated_duration'] ?? null,
            'difficulty' => $data['difficulty'] ?? 'basic',
            'grade_min' => $data['grade_min'] ?? null,
            'grade_max' => $data['grade_max'] ?? null,
            'language' => $data['language'] ?? 'es',
            'created_by' => $user->id,
        ]);

        return response()->json([
            'data' => $resource->load('creator'),
        ], 201);
    }

    /**
     * Ver recurso
     */
    public function show(StudyResource $studyResource)
    {
        return response()->json([
            'data' => $studyResource->load('creator'),
        ]);
    }

    /**
     * Actualizar recurso
     */
    public function update(Request $request, StudyResource $studyResource)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'resource_type' => ['sometimes', Rule::in(array_map(
                fn($e) => $e->value,
                ResourceType::cases()
            ))],

            'url' => ['sometimes', 'url', 'max:500'],

            'estimated_duration' => ['nullable', 'integer', 'between:1,999'],
            'difficulty' => ['nullable', Rule::in(['basic', 'intermediate', 'advanced'])],

            'grade_min' => ['nullable', 'integer', 'between:1,12'],
            'grade_max' => ['nullable', 'integer', 'between:1,12', 'gte:grade_min'],

            'language' => ['nullable', 'string', 'max:10'],
        ]);

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        $studyResource->fill($data);
        $studyResource->save();

        return response()->json([
            'data' => $studyResource->fresh()->load('creator'),
        ]);
    }

    /**
     * Eliminar recurso
     */
    public function destroy(StudyResource $studyResource)
    {
        $studyResource->delete();

        return response()->json([
            'message' => 'Recurso eliminado',
        ]);
    }
}