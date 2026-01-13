<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    /**
     * Listar grupos
     */
    public function index(Request $request)
    {
        $query = Group::query()->orderByDesc('year')->orderBy('grade')->orderBy('section');

        if ($request->filled('grade')) {
            $query->where('grade', (int) $request->input('grade'));
        }

        if ($request->filled('section')) {
            $query->where('section', strtoupper($request->string('section')->toString()));
        }

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Crear grupo
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'grade' => ['required', 'integer', 'between:6,12'],
            'section' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D'])],

            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'group_code' => ['nullable', 'string', 'max:40'],
        ]);

        $group = Group::create([
            'name' => trim($data['name']),
            'grade' => (int) $data['grade'],
            'section' => strtoupper($data['section']),
            'year' => $data['year'] ?? (int) date('Y'),
            'group_code' => $data['group_code'] ?? null,
            'student_count' => 0,
        ]);

        return response()->json([
            'data' => $group,
        ], 201);
    }

    /**
     * Ver grupo + estudiantes activos
     */
    public function show(Group $group)
    {
        $students = $group->students()
            ->with('user')
            ->wherePivotNull('left_at')
            ->get();

        return response()->json([
            'data' => [
                'group' => $group,
                'students' => $students,
            ],
        ]);
    }

    /**
     * Actualizar grupo
     */
    public function update(Request $request, Group $group)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'grade' => ['sometimes', 'integer', 'between:6,12'],
            'section' => ['sometimes', 'string', Rule::in(['A', 'B', 'C', 'D'])],

            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'group_code' => ['nullable', 'string', 'max:40'],
        ]);

        if (isset($data['section'])) {
            $data['section'] = strtoupper($data['section']);
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $group->fill($data);
        $group->save();

        return response()->json([
            'data' => $group,
        ]);
    }

    /**
     * Eliminar grupo
     * - Si tiene estudiantes activos, no permitir (recomendado para evitar huérfanos)
     */
    public function destroy(Group $group)
    {
        $activeCount = $group->students()->wherePivotNull('left_at')->count();

        if ($activeCount > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un grupo con estudiantes asignados',
            ], 409);
        }

        $group->delete();

        return response()->json(['message' => 'Grupo eliminado']);
    }

    /**
     * Asignar estudiantes al grupo (alta)
     * body: { "student_user_ids": ["uuid", ...] }
     */
    public function addStudents(Request $request, Group $group)
    {
        $data = $request->validate([
            'student_user_ids' => ['required', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
        ]);

        return DB::transaction(function () use ($group, $data) {
            // Solo estudiantes del tenant (TenantScoped en Student)
            $students = Student::whereIn('user_id', $data['student_user_ids'])->pluck('user_id')->all();

            $now = now();

            foreach ($students as $studentUserId) {
                // Si existe registro previo con left_at, lo "reactivamos"
                $existing = DB::table('group_students')
                    ->where('group_id', $group->id)
                    ->where('student_user_id', $studentUserId)
                    ->first();

                if ($existing) {
                    DB::table('group_students')
                        ->where('group_id', $group->id)
                        ->where('student_user_id', $studentUserId)
                        ->update([
                            'joined_at' => $existing->joined_at ?? $now,
                            'left_at' => null,
                        ]);
                } else {
                    DB::table('group_students')->insert([
                        'group_id' => $group->id,
                        'student_user_id' => $studentUserId,
                        'joined_at' => $now,
                        'left_at' => null,
                    ]);
                }
            }

            $this->recountStudents($group);

            return response()->json([
                'message' => 'Estudiantes asignados',
                'data' => [
                    'group' => $group->fresh(),
                ],
            ]);
        });
    }

    /**
     * Remover estudiantes del grupo (baja lógica)
     * body: { "student_user_ids": ["uuid", ...] }
     */
    public function removeStudents(Request $request, Group $group)
    {
        $data = $request->validate([
            'student_user_ids' => ['required', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
        ]);

        return DB::transaction(function () use ($group, $data) {
            $now = now();

            DB::table('group_students')
                ->where('group_id', $group->id)
                ->whereIn('student_user_id', $data['student_user_ids'])
                ->whereNull('left_at')
                ->update(['left_at' => $now]);

            $this->recountStudents($group);

            return response()->json([
                'message' => 'Estudiantes removidos',
                'data' => [
                    'group' => $group->fresh(),
                ],
            ]);
        });
    }

    /**
     * Recalcular student_count (RN-STU-012)
     */
    private function recountStudents(Group $group): void
    {
        $count = $group->students()->wherePivotNull('left_at')->count();
        $group->student_count = $count;
        $group->save();
    }
}
