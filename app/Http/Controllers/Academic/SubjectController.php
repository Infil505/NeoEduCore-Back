<?php

namespace App\Http\Controllers\Academic;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * Listar materias (tenant scoped)
     *
     * Filtros opcionales:
     * - search: coincidencia parcial sobre el nombre (case-insensitive)
     * - per_page: 1..100 (default 20)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        $request->validate([
            'search'   => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Subject::query()
            ->where('institution_id', $user->institution_id)
            ->withCount('exams')
            ->orderBy('name');

        if ($request->filled('search')) {
            // Escapamos los comodines de LIKE para que sean literales
            $term = addcslashes($request->string('search')->trim()->toString(), '%_\\');
            $query->where('name', 'ilike', "%{$term}%");
        }

        return response()->json([
            'data' => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    /**
     * Crear materia (tenant scoped)
     * - Solo el perfil administrador puede dar de alta materias
     * - institution_id sale del usuario autenticado
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user, 'crear')) {
            return $denied;
        }

        $data = $request->validate([
            'name' => $this->nameRules($user->institution_id),
        ]);

        $subject = Subject::create([
            'institution_id' => $user->institution_id, // ✅ CLAVE
            'name' => trim($data['name']),
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
     * Renombrar materia (tenant scoped)
     * - Solo el perfil administrador
     */
    public function update(Request $request, Subject $subject)
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user, 'renombrar')) {
            return $denied;
        }

        if ($subject->institution_id !== $user->institution_id) {
            return response()->json([
                'message' => 'No autorizado para modificar esta materia.',
            ], 403);
        }

        $data = $request->validate([
            'name' => $this->nameRules($user->institution_id, $subject->id),
        ]);

        $subject->name = trim($data['name']);
        $subject->save();

        return response()->json([
            'data' => $subject->fresh(),
        ]);
    }

    /**
     * Eliminar materia.
     * - Solo el perfil administrador
     * Cascada DB: exámenes → preguntas → opciones → intentos → respuestas.
     */
    public function destroy(Request $request, Subject $subject)
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user, 'eliminar')) {
            return $denied;
        }

        if ($subject->institution_id !== $user->institution_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $subject->delete();

        return response()->noContent();
    }

    /* =========================
     |  Helpers
     ========================= */

    /**
     * El catálogo de materias define la oferta académica de la institución,
     * por eso sus mutaciones son admin-only.
     *
     * Defensa en profundidad: las rutas ya están bajo 'role:admin', pero no
     * dependemos únicamente del cableado de rutas para esta restricción.
     */
    private function denyIfNotAdmin(?object $user, string $accion): ?JsonResponse
    {
        if (!$user || $user->user_type !== UserType::Admin) {
            return response()->json([
                'message' => "Solo un administrador puede {$accion} materias.",
            ], 403);
        }

        if (!$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        return null;
    }

    /**
     * Reglas del nombre. El nombre debe ser único dentro de la institución,
     * ignorando mayúsculas/minúsculas y espacios sobrantes: pueden coexistir
     * "Matemática 1er grado" y "Matemática 2do grado", pero no dos iguales.
     *
     * Espejo de la constraint UNIQUE (institution_id, lower(btrim(name)))
     * — la validación da un 422 legible; la BD es la garantía real ante carreras.
     */
    private function nameRules(string $institutionId, ?string $ignoreId = null): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:120',
            function (string $attribute, mixed $value, \Closure $fail) use ($institutionId, $ignoreId) {
                $duplicado = Subject::query()
                    ->where('institution_id', $institutionId)
                    ->whereRaw('lower(btrim(name)) = lower(btrim(?))', [(string) $value])
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();

                if ($duplicado) {
                    $fail('Ya existe una materia con ese nombre en la institución.');
                }
            },
        ];
    }
}
