<?php

namespace App\Http\Controllers;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OverviewController extends Controller
{
    public function staffOverview(Request $request)
    {
        $institutionId = $request->user()->institution_id;
        $includeInstitutions = $request->boolean('include_institutions')
            && $request->user()->user_type->value === 'admin';

        $subjects = Subject::query()
            ->orderBy('name')
            ->get();

        $subjectIds = $subjects->pluck('id');

        $users = User::query()
            ->where('institution_id', $institutionId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $students = Student::query()
            ->with('user')
            ->orderBy('student_code')
            ->limit(20)
            ->get();

        $groups = Group::query()
            ->orderByDesc('year')
            ->orderBy('grade')
            ->orderBy('section')
            ->get();

        $exams = Exam::query()
            ->with(['subject', 'teacher'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $calendar = CalendarEvent::query()
            ->with(['creator', 'group', 'exam'])
            ->orderBy('start_at')
            ->limit(30)
            ->get();

        $resources = StudyResource::query()
            ->with('creator')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $avgPct = ExamAttempt::whereNotNull('submitted_at')
            ->where('max_score', '>', 0)
            ->selectRaw('AVG(score / max_score * 100) as avg_pct')
            ->value('avg_pct');

        $progressStats = StudentProgress::whereIn('subject_id', $subjectIds)
            ->selectRaw('subject_id, COUNT(*) as student_count, AVG(mastery_percentage) as avg_mastery')
            ->groupBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $examCounts = Exam::whereIn('subject_id', $subjectIds)
            ->selectRaw('subject_id, COUNT(*) as exams_count')
            ->groupBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $analyticsSubjects = $subjects->map(function ($subject) use ($progressStats, $examCounts) {
            $progress = $progressStats->get($subject->id);
            $examCount = $examCounts->get($subject->id);

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'exams_count' => $examCount ? (int) $examCount->exams_count : 0,
                'enrolled_students' => $progress ? (int) $progress->student_count : 0,
                'average_mastery' => $progress ? round((float) $progress->avg_mastery, 2) : 0,
            ];
        })->values();

        return response()->json([
            'data' => [
                'users' => $users,
                'students' => $students,
                'subjects' => $subjects,
                'groups' => $groups,
                'exams' => $exams,
                'calendar' => $calendar,
                'resources' => $resources,
                'analyticsInstitution' => [
                    'total_students' => Student::count(),
                    'active_students' => Student::where('status', 'active')->count(),
                    'exams_completed' => ExamAttempt::whereNotNull('submitted_at')->count(),
                    'average_score_pct' => $avgPct ? round((float) $avgPct, 2) : 0,
                ],
                'analyticsSubjects' => $analyticsSubjects,
                'institutions' => $includeInstitutions
                    ? Institution::query()->orderByDesc('created_at')->limit(20)->get()
                    : [],
            ],
        ]);
    }

    public function studentOverview(Request $request)
    {
        $user = $request->user();

        $student = Student::query()
            ->with(['user.institution', 'groups', 'progress.subject'])
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        $groupIds = $student->groups->pluck('id');

        $exams = $groupIds->isEmpty()
            ? collect()
            : Exam::query()
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('available_from')->orWhere('available_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('available_until')->orWhere('available_until', '>=', now()))
                ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $groupIds))
                ->withCount(['attempts as submitted_count' => fn ($query) => $query
                    ->where('student_user_id', $user->id)
                    ->whereNotNull('submitted_at')])
                ->with('subject')
                ->get()
                ->filter(fn ($exam) => $exam->submitted_count < $exam->max_attempts)
                ->values();

        $progress = StudentProgress::query()
            ->with('subject')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $recommendations = AiRecommendation::query()
            ->with(['subject', 'exam'])
            ->where('student_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $calendar = CalendarEvent::query()
            ->with(['creator', 'group', 'exam'])
            ->orderBy('start_at')
            ->limit(30)
            ->get();

        $sessions = AiChatSession::query()
            ->select('id', 'student_user_id', 'subject_id', 'exam_id', 'ended_at', 'created_at', 'updated_at')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        $subjects = $student->subjects()->get()->map(fn ($subject) => [
            'subject_id' => $subject->id,
            'name' => $subject->name,
            'enrolled_at' => $subject->pivot->enrolled_at,
        ])->values();

        return response()->json([
            'data' => [
                'profile' => $student,
                'exams' => $exams,
                'progress' => $progress,
                'recommendations' => $recommendations,
                'calendar' => $calendar,
                'tutorSessions' => $sessions,
                'diagnosis' => $this->buildStudentDiagnosis($student),
                'subjects' => $subjects,
            ],
        ]);
    }

    private function buildStudentDiagnosis(Student $student): array
    {
        /** @var Collection<int, StudentProgress> $progress */
        $progress = $student->progress
            ->sortBy('mastery_percentage')
            ->values();

        if ($progress->isEmpty()) {
            return [
                'summary' => 'Aun no hay suficiente progreso registrado para generar un diagnostico detallado. Completa tu siguiente examen para empezar a ver recomendaciones.',
                'focus_area' => 'Primer avance academico',
            ];
        }

        /** @var StudentProgress $lowest */
        $lowest = $progress->first();
        /** @var StudentProgress $highest */
        $highest = $progress->sortByDesc('mastery_percentage')->first();
        $average = round((float) $progress->avg('mastery_percentage'));

        $focusArea = $lowest->subject?->name ?? 'Seguimiento general';
        $strengthArea = $highest->subject?->name ?? 'tu mejor materia actual';

        return [
            'summary' => "Tu promedio actual es {$average}%. Tu mejor avance va en {$strengthArea}, mientras que {$focusArea} necesita mas refuerzo esta semana. Prioriza practicar esa materia y luego vuelve a medir tu progreso.",
            'focus_area' => $focusArea,
        ];
    }
}
