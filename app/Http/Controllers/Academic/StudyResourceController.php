<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Concerns\ExigeAutoria;
use App\Http\Controllers\Concerns\ResuelveAulasDestino;
use App\Http\Controllers\Controller;
use App\Enums\Difficulty;
use App\Enums\ResourceType;
use App\Enums\UserType;
use App\Models\Academic\StudyResource;
use App\Services\AI\AiOutputValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudyResourceController extends Controller
{
    use ExigeAutoria, ResuelveAulasDestino;

    /**
     * Qué se ve del autor y de las aulas según quién mira. El alumno no necesita
     * el correo del docente ni saber a qué otras aulas llegó el recurso.
     */
    private function acotarParaEstudiante(?object $user, iterable $recursos): void
    {
        if (!$user || $user->user_type !== UserType::Student) {
            return;
        }

        foreach ($recursos as $recurso) {
            $recurso->makeHidden('groups');

            if ($recurso->relationLoaded('creator') && $recurso->creator !== null) {
                $recurso->creator->setVisible(['id', 'full_name']);
            }
        }
    }

    /**
     * Listar recursos (con filtros). Cada rol ve lo suyo: ver
     * `StudyResource::scopeVisibleTo()`.
     */
    public function index(Request $request)
    {
        $query = StudyResource::query()
            ->visibleTo($request->user())
            ->with(['creator', 'subject', 'groups'])
            ->orderByDesc('created_at');

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->string('resource_type')->toString());
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->string('difficulty')->toString());
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->string('subject_id')->toString());
        }

        if ($request->filled('grade')) {
            $grade = (int) $request->input('grade');
            $query->where(function ($q) use ($grade) {
                $q->whereNull('grade_min')->orWhere('grade_min', '<=', $grade);
            })->where(function ($q) use ($grade) {
                $q->whereNull('grade_max')->orWhere('grade_max', '>=', $grade);
            });
        }

        $paginator = $query->paginate(config('pagination.default'));

        $this->acotarParaEstudiante($request->user(), $paginator->getCollection());

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Crear recurso (solo docentes: la ruta es `role:teacher`).
     *
     * `group_ids`: aulas a las que se envía, de las que el administrador le
     * asignó. Con una sola aula asignada se usa esa; con varias hay que elegir.
     * Si el recurso lleva materia, solo valen las aulas donde la imparte.
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

            'url' => ['required', 'url', 'max:500', function ($attr, $value, $fail) {
                if (!(new AiOutputValidator())->isUrlAllowed($value)) {
                    $fail('La URL debe provenir de un dominio educativo permitido.');
                }
            }],

            // Materia del recurso (D2). Acotada a la institución del usuario: con
            // un `exists` a secas se podría referenciar la materia de otro centro,
            // porque `Rule::exists` va por el query builder y no pasa por el
            // scope de tenant de Eloquent.
            'subject_id' => ['nullable', 'uuid', Rule::exists('subjects', 'id')
                ->where('institution_id', $request->user()->institution_id)],

            'estimated_duration' => ['nullable', 'integer', 'between:1,999'],
            'difficulty' => ['nullable', Rule::in(Difficulty::values())],

            'grade_min' => ['nullable', 'integer', 'between:1,12'],
            'grade_max' => ['nullable', 'integer', 'between:1,12', 'gte:grade_min'],

            'language' => ['nullable', 'string', 'max:10'],

            'group_ids'   => ['nullable', 'array'],
            'group_ids.*' => ['uuid', 'distinct'],
        ]);

        $user = $request->user();

        $grupos = $this->resolverAulasDestino($user, $data['group_ids'] ?? null, $data['subject_id'] ?? null);
        if ($grupos instanceof \Illuminate\Http\JsonResponse) {
            return $grupos;
        }

        $resource = StudyResource::create([
            'subject_id' => $data['subject_id'] ?? null,
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

        $resource->syncGroups($grupos);

        return response()->json([
            'data' => $resource->load(['creator', 'subject', 'groups']),
        ], 201);
    }

    /**
     * Ver recurso. 404 y no 403 si no es visible para quien pregunta: confirmar
     * que existe ya le diría a un docente que otro colega tiene ese material.
     */
    public function show(Request $request, StudyResource $studyResource)
    {
        if (!StudyResource::query()->whereKey($studyResource->getKey())->visibleTo($request->user())->exists()) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $studyResource->load(['creator', 'subject', 'groups']);
        $this->acotarParaEstudiante($request->user(), [$studyResource]);

        return response()->json([
            'data' => $studyResource,
        ]);
    }

    /**
     * Actualizar recurso
     */
    public function update(Request $request, StudyResource $studyResource)
    {
        // S6: el recurso es de quien lo subió. La biblioteca se lee entre todos,
        // pero no se reescribe el material ajeno.
        if (! $this->esSuyoOEsAdmin($request->user(), $studyResource->created_by, 'este recurso')) {
            return $this->noAutorizadoPorAutoria('este recurso');
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'resource_type' => ['sometimes', Rule::in(array_map(
                fn($e) => $e->value,
                ResourceType::cases()
            ))],

            'url' => ['sometimes', 'url', 'max:500', function ($attr, $value, $fail) {
                if (!(new AiOutputValidator())->isUrlAllowed($value)) {
                    $fail('La URL debe provenir de un dominio educativo permitido.');
                }
            }],

            // Materia del recurso (D2). Acotada a la institución del usuario: con
            // un `exists` a secas se podría referenciar la materia de otro centro,
            // porque `Rule::exists` va por el query builder y no pasa por el
            // scope de tenant de Eloquent.
            'subject_id' => ['nullable', 'uuid', Rule::exists('subjects', 'id')
                ->where('institution_id', $request->user()->institution_id)],

            'estimated_duration' => ['nullable', 'integer', 'between:1,999'],
            'difficulty' => ['nullable', Rule::in(Difficulty::values())],

            'grade_min' => ['nullable', 'integer', 'between:1,12'],
            'grade_max' => ['nullable', 'integer', 'between:1,12', 'gte:grade_min'],

            'language' => ['nullable', 'string', 'max:10'],

            'group_ids'   => ['sometimes', 'array', 'min:1'],
            'group_ids.*' => ['uuid', 'distinct'],
        ]);

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        // Las aulas se revalidan si cambian ellas o cambia la materia: mover un
        // recurso a otra materia no puede dejarlo en aulas donde no se imparte.
        $grupos = null;
        if (array_key_exists('group_ids', $data) || array_key_exists('subject_id', $data)) {
            $materia = array_key_exists('subject_id', $data) ? $data['subject_id'] : $studyResource->subject_id;
            $pedidas = $data['group_ids'] ?? $studyResource->groups()->pluck('groups.id')->all();

            // Sin aulas ni antes ni ahora no hay nada que revalidar.
            if (!empty($pedidas)) {
                $grupos = $this->resolverAulasDestino($request->user(), $pedidas, $materia);
                if ($grupos instanceof \Illuminate\Http\JsonResponse) {
                    return $grupos;
                }
            }
        }

        unset($data['group_ids']);

        $studyResource->fill($data);
        $studyResource->save();

        if ($grupos !== null) {
            $studyResource->syncGroups($grupos);
        }

        return response()->json([
            'data' => $studyResource->fresh()->load(['creator', 'subject', 'groups']),
        ]);
    }

    /**
     * Eliminar recurso
     */
    public function destroy(Request $request, StudyResource $studyResource)
    {
        if (! $this->esSuyoOEsAdmin($request->user(), $studyResource->created_by, 'este recurso')) {
            return $this->noAutorizadoPorAutoria('este recurso');
        }

        $studyResource->delete();

        return response()->noContent();
    }
}
