<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use App\Models\Academic\StudentSubject;
use App\Models\Students\Student;
use Illuminate\Http\Request;

class StudentSubjectController extends Controller
{
    /**
     * GET /api/students/{student_user_id}/subjects
     * Lista las materias en las que está inscrito un estudiante (admin/teacher).
     */
    public function index(string $studentUserId, Request $request)
    {
        $student = Student::where('user_id', $studentUserId)->firstOrFail();

        $subjects = $student->subjects()->get()->map(fn ($s) => [
            'subject_id'  => $s->id,
            'name'        => $s->name,
            'enrolled_at' => $s->pivot->enrolled_at,
        ]);

        return response()->json(['data' => $subjects]);
    }

    /**
     * GET /api/students/me/subjects
     * Materias propias del estudiante autenticado.
     */
    public function mySubjects(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        $subjects = $student->subjects()->get()->map(fn ($s) => [
            'subject_id'  => $s->id,
            'name'        => $s->name,
            'enrolled_at' => $s->pivot->enrolled_at,
        ]);

        return response()->json(['data' => $subjects]);
    }

    /**
     * POST /api/students/{student_user_id}/subjects
     * Inscribir uno o varios estudiantes a una materia (admin/teacher).
     * body: { "subject_id": "uuid" }
     */
    public function enroll(string $studentUserId, Request $request)
    {
        $data = $request->validate([
            'subject_id' => ['required', 'uuid', 'exists:subjects,id'],
        ]);

        $student = Student::where('user_id', $studentUserId)->firstOrFail();
        $subject = Subject::findOrFail($data['subject_id']);

        $existing = StudentSubject::where('student_user_id', $studentUserId)
            ->where('subject_id', $subject->id)
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'El estudiante ya está inscrito en esta materia'], 409);
        }

        StudentSubject::create([
            'institution_id'  => $student->institution_id,
            'student_user_id' => $studentUserId,
            'subject_id'      => $subject->id,
            'enrolled_at'     => now(),
        ]);

        return response()->json([
            'message' => 'Inscripción registrada',
            'data'    => [
                'student_user_id' => $studentUserId,
                'subject_id'      => $subject->id,
                'subject_name'    => $subject->name,
            ],
        ], 201);
    }

    /**
     * DELETE /api/students/{student_user_id}/subjects/{subject}
     * Desinscribir a un estudiante de una materia (admin/teacher).
     */
    public function unenroll(string $studentUserId, string $subjectId)
    {
        $deleted = StudentSubject::where('student_user_id', $studentUserId)
            ->where('subject_id', $subjectId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        return response()->json(['message' => 'Estudiante desinscrito de la materia']);
    }
}
