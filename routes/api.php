<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AI\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Students\StudentController;
use App\Http\Controllers\Academic\GroupController;
use App\Http\Controllers\Academic\SubjectController;
use App\Http\Controllers\Exams\ExamController;
use App\Http\Controllers\Exams\QuestionController;
use App\Http\Controllers\Exams\ExamAttemptController;
use App\Http\Controllers\Students\StudentAnswerController;
use App\Http\Controllers\Students\StudentProgressController;
use App\Http\Controllers\Academic\StudyResourceController;
use App\Http\Controllers\Academic\CalendarEventController;
use App\Http\Controllers\AI\AiRecommendationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\InstitutionController;
use App\Http\Controllers\Admin\ReportController;

/*
|--------------------------------------------------------------------------
| HEALTH (público)
|--------------------------------------------------------------------------
*/
Route::get('/ping', fn () => response()->json(['ok' => true]));

/*
|--------------------------------------------------------------------------
| AUTH PÚBLICO
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

/*
|--------------------------------------------------------------------------
| PASSWORD RESET (público, throttled)
|--------------------------------------------------------------------------
*/
Route::prefix('password')->group(function () {
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')->name('password.email');
    Route::post('/verify', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:10,1')->name('password.verify');
    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:5,1')->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| PROTEGIDAS: auth + tenant (aplica a todo lo de abajo)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    /*
    | Sesión — cualquier rol autenticado
    */
    Route::get('/auth/me', [AuthController::class, 'me'])->name('me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/password/change', [ForgotPasswordController::class, 'changePassword'])
        ->middleware('throttle:5,1')->name('password.change');

    /*
    | Perfil propio del estudiante — solo student
    */
    Route::get('/students/me', [StudentController::class, 'me'])
        ->middleware('role:student');

    /*
    | Intentos de examen — solo student
    */
    Route::middleware('role:student')->group(function () {
        Route::post('/exams/{exam}/attempts/start', [ExamAttemptController::class, 'start']);
        Route::post('/exams/{exam}/attempts/{attempt}/submit', [ExamAttemptController::class, 'submit']);
        Route::get('/exams/{exam}/attempts/{attempt}', [ExamAttemptController::class, 'show']);
        Route::post('/exam-attempts/{attempt}/recommendations/regenerate',
            [ExamAttemptController::class, 'regenerateRecommendations']
        )->middleware('throttle:5,1');
        Route::get('/student-progress/me', [StudentProgressController::class, 'me']);
        Route::get('/ai-recommendations/me', [AiRecommendationController::class, 'myRecommendations']);
    });

    /*
    | Lectura compartida — admin, teacher y student (no parent por ahora)
    | GET de catálogos y contenido educativo
    */
    Route::middleware('role:admin,teacher,student')->group(function () {
        Route::get('/exams', [ExamController::class, 'index']);
        Route::get('/exams/{exam}', [ExamController::class, 'show']);
        Route::get('/exams/{exam}/questions', [QuestionController::class, 'index']);
        Route::get('/subjects', [SubjectController::class, 'index']);
        Route::get('/subjects/{subject}', [SubjectController::class, 'show']);
        Route::get('/study-resources', [StudyResourceController::class, 'index']);
        Route::get('/study-resources/{study_resource}', [StudyResourceController::class, 'show']);
        Route::get('/calendar-events', [CalendarEventController::class, 'index']);
        Route::get('/calendar-events/{calendar_event}', [CalendarEventController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN + TEACHER — gestión completa
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,teacher')->group(function () {

        // Gestión de usuarios
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/status', [UserController::class, 'setStatus']);
        Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        // Gestión de estudiantes
        Route::get('/students', [StudentController::class, 'index']);
        Route::post('/students/bulk-upload', [StudentController::class, 'bulkUpload']);
        Route::get('/students/{student_user_id}', [StudentController::class, 'show']);
        Route::put('/students/{student_user_id}', [StudentController::class, 'update']);
        Route::patch('/students/{student_user_id}/status', [StudentController::class, 'setStatus']);

        // Grupos y materias (CRUD)
        Route::apiResource('groups', GroupController::class);
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::patch('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

        // Exámenes: mutaciones (lectura ya cubierta en shared)
        Route::post('/exams', [ExamController::class, 'store']);
        Route::put('/exams/{exam}', [ExamController::class, 'update']);
        Route::patch('/exams/{exam}', [ExamController::class, 'update']);
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

        // Preguntas (CRUD)
        Route::post('/exams/{exam}/questions', [QuestionController::class, 'store']);
        Route::put('/questions/{question}', [QuestionController::class, 'update']);
        Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);

        // Revisión de respuestas (corrección manual por docente)
        Route::get('/exam-attempts/{attempt}/answers', [StudentAnswerController::class, 'index']);
        Route::patch('/student-answers/{studentAnswer}/review', [StudentAnswerController::class, 'review']);

        // Progreso de estudiantes (vista completa)
        Route::get('/student-progress', [StudentProgressController::class, 'index']);
        Route::post('/student-progress', [StudentProgressController::class, 'upsert']);
        Route::post('/student-progress/recalc', [StudentProgressController::class, 'recalcFromAttempts']);

        // Recursos de estudio (CRUD)
        Route::post('/study-resources', [StudyResourceController::class, 'store']);
        Route::put('/study-resources/{study_resource}', [StudyResourceController::class, 'update']);
        Route::patch('/study-resources/{study_resource}', [StudyResourceController::class, 'update']);
        Route::delete('/study-resources/{study_resource}', [StudyResourceController::class, 'destroy']);

        // Eventos del calendario (CRUD)
        Route::post('/calendar-events', [CalendarEventController::class, 'store']);
        Route::put('/calendar-events/{calendar_event}', [CalendarEventController::class, 'update']);
        Route::patch('/calendar-events/{calendar_event}', [CalendarEventController::class, 'update']);
        Route::delete('/calendar-events/{calendar_event}', [CalendarEventController::class, 'destroy']);

        // IA: generación manual de recomendaciones por docente
        Route::post('/ai/generate', [AiController::class, 'generate']);
        Route::get('/ai-recommendations', [AiRecommendationController::class, 'index']);
        Route::get('/ai-recommendations/{aiRecommendation}', [AiRecommendationController::class, 'show']);

        // Reportes
        Route::get('/reports/exams/{exam}/results', [ReportController::class, 'examResults']);
        Route::get('/reports/exams/{exam}/results.csv', [ReportController::class, 'exportExamResultsCsv']);
        Route::get('/reports/students/{student_user_id}/history', [ReportController::class, 'studentHistory']);
    });

    /*
    |--------------------------------------------------------------------------
    | SOLO ADMIN — gestión de instituciones (SaaS)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('/institutions', [InstitutionController::class, 'index']);
        Route::get('/institutions/{institution}', [InstitutionController::class, 'show']);
        Route::put('/institutions/{institution}', [InstitutionController::class, 'update']);
        Route::patch('/institutions/{institution}/toggle', [InstitutionController::class, 'toggleStatus']);
    });
});
