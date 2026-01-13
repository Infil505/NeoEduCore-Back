<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\ExamAttemptController;
use App\Http\Controllers\StudentAnswerController;
use App\Http\Controllers\StudentProgressController;
use App\Http\Controllers\StudyResourceController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\AiRecommendationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| HEALTH
|--------------------------------------------------------------------------
*/
Route::get('/ping', fn () => response()->json(['ok' => true]));

/*
|--------------------------------------------------------------------------
| AI
|--------------------------------------------------------------------------
*/
Route::post('/ai/generate', [AiController::class, 'generate']);

/*
|--------------------------------------------------------------------------
| AUTH (API)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

/*
|--------------------------------------------------------------------------
| PASSWORD RESET (API)
|--------------------------------------------------------------------------
*/
Route::prefix('password')->group(function () {
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::post('/verify', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:10,1')
        ->name('password.verify');

    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (SANCTUM)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    | AUTH SESSION
    */
    Route::get('/auth/me', [AuthController::class, 'me'])->name('me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');

    Route::post('/password/change', [ForgotPasswordController::class, 'changePassword'])
        ->middleware('throttle:5,1')
        ->name('password.change');

    /*
    |--------------------------------------------------------------------------
    | STUDENTS
    |--------------------------------------------------------------------------
    */
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/me', [StudentController::class, 'me']);
    Route::get('/students/{student_user_id}', [StudentController::class, 'show']);
    Route::put('/students/{student_user_id}', [StudentController::class, 'update']);
    Route::patch('/students/{student_user_id}/status', [StudentController::class, 'setStatus']);

    /*
    |--------------------------------------------------------------------------
    | GROUPS
    |--------------------------------------------------------------------------
    */
    Route::apiResource('groups', GroupController::class);

    /*
    |--------------------------------------------------------------------------
    | SUBJECTS
    |--------------------------------------------------------------------------
    */
    Route::apiResource('subjects', SubjectController::class);

    /*
    |--------------------------------------------------------------------------
    | EXAMS & QUESTIONS
    |--------------------------------------------------------------------------
    */
    Route::apiResource('exams', ExamController::class);

    Route::get('/exams/{exam}/questions', [QuestionController::class, 'index']);
    Route::post('/exams/{exam}/questions', [QuestionController::class, 'store']);
    Route::put('/questions/{question}', [QuestionController::class, 'update']);
    Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | EXAM ATTEMPTS
    |--------------------------------------------------------------------------
    */
    Route::post('/exams/{exam}/attempts/start', [ExamAttemptController::class, 'start']);
    Route::post('/exams/{exam}/attempts/{attempt}/submit', [ExamAttemptController::class, 'submit']);
    Route::get('/exams/{exam}/attempts/{attempt}', [ExamAttemptController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | STUDENT ANSWERS (REVIEW)
    |--------------------------------------------------------------------------
    */
    Route::get('/exam-attempts/{attempt}/answers', [StudentAnswerController::class, 'index']);
    Route::patch('/student-answers/{studentAnswer}/review', [StudentAnswerController::class, 'review']);

    /*
    |--------------------------------------------------------------------------
    | STUDENT PROGRESS
    |--------------------------------------------------------------------------
    */
    Route::get('/student-progress', [StudentProgressController::class, 'index']);
    Route::get('/student-progress/me', [StudentProgressController::class, 'me']);
    Route::post('/student-progress', [StudentProgressController::class, 'upsert']);
    Route::post('/student-progress/recalc', [StudentProgressController::class, 'recalcFromAttempts']);

    /*
    |--------------------------------------------------------------------------
    | STUDY RESOURCES
    |--------------------------------------------------------------------------
    */
    Route::apiResource('study-resources', StudyResourceController::class);

    /*
    |--------------------------------------------------------------------------
    | CALENDAR EVENTS
    |--------------------------------------------------------------------------
    */
    Route::apiResource('calendar-events', CalendarEventController::class);

    /*
    |--------------------------------------------------------------------------
    | AI RECOMMENDATIONS
    |--------------------------------------------------------------------------
    */
    Route::get('/ai-recommendations', [AiRecommendationController::class, 'index']);
    Route::get('/ai-recommendations/me', [AiRecommendationController::class, 'myRecommendations']);
    Route::get('/ai-recommendations/{aiRecommendation}', [AiRecommendationController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | USERS (ADMIN / TEACHER)
    |--------------------------------------------------------------------------
    */
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}/status', [UserController::class, 'setStatus']);
    Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

    /*
    |--------------------------------------------------------------------------
    | INSTITUTIONS (SAAS ADMIN)
    |--------------------------------------------------------------------------
    */
    Route::get('/institutions', [InstitutionController::class, 'index']);
    Route::get('/institutions/{institution}', [InstitutionController::class, 'show']);
    Route::put('/institutions/{institution}', [InstitutionController::class, 'update']);
    Route::patch('/institutions/{institution}/toggle', [InstitutionController::class, 'toggleStatus']);

    /*
    |--------------------------------------------------------------------------
    | REPORTS
    |--------------------------------------------------------------------------
    */
    Route::get('/reports/exams/{exam}/results', [ReportController::class, 'examResults']);
    Route::get('/reports/exams/{exam}/results.csv', [ReportController::class, 'exportExamResultsCsv']);
    Route::get('/reports/students/{student_user_id}/history', [ReportController::class, 'studentHistory']);
});