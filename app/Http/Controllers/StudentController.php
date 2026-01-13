<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Listar estudiantes (admin/teacher)
     */
    public function index(Request $request)
    {
        $query = Student::query()
            ->with('user')
            ->orderBy('student_code');

        if ($request->filled('grade')) {
            $query->where('grade', (int) $request->input('grade'));
        }

        if ($request->filled('section')) {
            $query->where('section', strtoupper($request->string('section')->toString()));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Ver estudiante por user_id (uuid)
     */
    public function show(string $student_user_id)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        return response()->json([
            'data' => $student,
        ]);
    }

    /**
     * Actualizar datos del perfil del estudiante
     * (datos extra: birth_date, parent, group_code, etc.)
     */
    public function update(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        $data = $request->validate([
            'student_code' => ['sometimes', 'string', 'max:40'],
            'grade' => ['sometimes', 'integer', 'between:6,12'],
            'section' => ['sometimes', 'string', Rule::in(['A', 'B', 'C', 'D'])],

            'birth_date' => ['nullable', 'date'],
            'parent_name' => ['nullable', 'string', 'max:120'],
            'parent_email' => ['nullable', 'email', 'max:120'],

            'group_code' => ['nullable', 'string', 'max:40'],
        ]);

        if (isset($data['section'])) {
            $data['section'] = strtoupper($data['section']);
        }

        $student->fill($data);
        $student->save();

        return response()->json([
            'data' => $student->fresh()->load('user'),
        ]);
    }

    /**
     * Cambiar status del estudiante (admin/teacher)
     */
    public function setStatus(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        $data = $request->validate([
            'status' => ['required', Rule::in([
                StudentStatus::Active->value,
                StudentStatus::Inactive->value,
                StudentStatus::Suspended->value,
            ])],
        ]);

        $student->status = $data['status'];
        $student->save();

        return response()->json([
            'data' => $student,
        ]);
    }

    /**
     * Perfil del estudiante autenticado (mi perfil)
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $student = Student::with('user')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        return response()->json([
            'data' => $student,
        ]);
    }
}