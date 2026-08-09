<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AI\AiController;
use App\Http\Controllers\AI\AiTutorController;
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
use App\Http\Controllers\Admin\InstitutionAdminController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\SystemConfigController;
use App\Http\Controllers\Academic\StudentSubjectController;
use App\Http\Controllers\Academic\BulkReassignmentController;
use App\Http\Controllers\Academic\TeacherAssignmentController;

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
| El alta de usuarios (/register) NO es pública: solo un admin puede crear
| cuentas dentro de su institución (ver grupo role:admin más abajo).
| El primer admin se crea mediante el seeder (php artisan db:seed).
*/
// `throttle:login` cuenta por correo+IP y, además, por IP a secas. Es la puerta
// más atacada del sistema y hasta el 07/08/2026 no tenía ningún tope: con
// BCRYPT_ROUNDS=10 cada intento cuesta ~100 ms de CPU, así que servía tanto para
// fuerza bruta como para agotar los workers. Ver config/rate_limits.php.
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');

/*
|--------------------------------------------------------------------------
| PASSWORD RESET (público, throttled)
|--------------------------------------------------------------------------
*/
Route::prefix('password')->group(function () {
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:password')->name('password.email');
    Route::post('/verify', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:password-verify')->name('password.verify');
    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:password')->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| PROTEGIDAS: auth + tenant (aplica a todo lo de abajo)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    /*
    | Sesión — cualquier rol autenticado
    */
    Route::get('/auth/me', [AuthController::class, 'me'])->name('me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/password/change', [ForgotPasswordController::class, 'changePassword'])
        ->middleware('throttle:password')->name('password.change');

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
        Route::patch('/exams/{exam}/attempts/{attempt}/pause', [ExamAttemptController::class, 'pause']);
        Route::patch('/exams/{exam}/attempts/{attempt}/resume', [ExamAttemptController::class, 'resume']);
        Route::get('/exams/{exam}/attempts/{attempt}', [ExamAttemptController::class, 'show']);
        Route::post('/exam-attempts/{attempt}/recommendations/regenerate',
            [ExamAttemptController::class, 'regenerateRecommendations']
        )->middleware('throttle:ai-regenerate');
        Route::get('/student-progress/me', [StudentProgressController::class, 'me']);
        Route::get('/ai-recommendations/me', [AiRecommendationController::class, 'myRecommendations']);
        Route::get('/students/me/available-exams', [StudentController::class, 'availableExams']);

        // Estrategias del tutor, en el grupo de alumno y ANTES de la ruta con
        // comodín del grupo docente: si se registrara después, `me` entraría por
        // `{student_user_id}` y el alumno acabaría en una ruta que no le toca.
        Route::get('/reports/students/me/strategies', [ReportController::class, 'myStrategies']);

        // Tutor IA conversacional
        // Doble throttle: el primero acota al usuario, el segundo (de institución)
        // reserva workers para el flujo de examen. Ver bootstrap/app.php.
        Route::post('/ai/tutor/chat', [AiTutorController::class, 'chat'])
            ->middleware(['throttle:ai-chat', 'throttle:ai-global']);
        Route::get('/ai/tutor/diagnosis', [AiTutorController::class, 'diagnosis'])
            ->middleware(['throttle:ai-diagnosis', 'throttle:ai-global']);
        Route::patch('/ai/tutor/sessions/{sessionId}/end', [AiTutorController::class, 'endSession']);
        Route::get('/ai/tutor/sessions', [AiTutorController::class, 'sessions']);
    });

    /*
    | Lectura compartida — admin, teacher y student (el superadmin queda fuera:
    | es externo a las instituciones y no ve datos académicos de ninguna)
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

        // Gestión de usuarios — solo LECTURA para teacher.
        // Las mutaciones (alta, update, status, reset-password, delete) son admin-only.
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);

        // Gestión de estudiantes
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/{student_user_id}', [StudentController::class, 'show']);
        Route::put('/students/{student_user_id}', [StudentController::class, 'update']);
        Route::patch('/students/{student_user_id}/status', [StudentController::class, 'setStatus']);

        // Grupos (CRUD)
        // OJO: las materias son admin-only en todas sus mutaciones, ver más abajo.
        Route::apiResource('groups', GroupController::class);

        // OJO: la membresía de un grupo (alta y baja de estudiantes) es
        // admin-only desde el modelo de asignaciones, ver más abajo.

        // Exámenes: mutaciones (lectura ya cubierta en shared)
        Route::post('/exams', [ExamController::class, 'store']);
        Route::put('/exams/{exam}', [ExamController::class, 'update']);
        Route::patch('/exams/{exam}', [ExamController::class, 'update']);
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

        // Transiciones de estado: draft → published → active → completed.
        //
        // ⚠️ Esta ruta FALTABA hasta el 08/08/2026. `setStatus()` existía entero
        // —con sus reglas de transición— pero no lo llamaba nadie, y `update()`
        // no acepta `status`. El efecto era que ningún examen podía salir de
        // `draft`, y como `Exam::scopeVisibleTo()` exige `active` para el
        // alumnado, **el flujo central del sistema era inalcanzable por API**.
        // Los tests no lo detectaban porque activan los exámenes por factory.
        Route::patch('/exams/{exam}/status', [ExamController::class, 'setStatus']);

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
        // También llama a OpenAI → también entra en el presupuesto global de IA
        Route::post('/ai/generate', [AiController::class, 'generate'])
            ->middleware(['throttle:ai-generate', 'throttle:ai-global']);
        Route::get('/ai-recommendations', [AiRecommendationController::class, 'index']);
        Route::get('/ai-recommendations/{aiRecommendation}', [AiRecommendationController::class, 'show']);

        // Reportes
        Route::get('/reports/exams/{exam}/results', [ReportController::class, 'examResults']);
        Route::get('/reports/exams/{exam}/results.csv', [ReportController::class, 'exportExamResultsCsv']);
        Route::get('/reports/students/{student_user_id}/history', [ReportController::class, 'studentHistory']);
        Route::get('/reports/students/{student_user_id}/history.csv', [ReportController::class, 'exportStudentHistoryCsv']);

        // Resúmenes agregados para los gráficos y el PDF que arma el frontend.
        // Van aparte de los listados paginados: quien solo quiere la tabla no
        // paga los agregados, y quien solo quiere los gráficos no pagina.
        Route::get('/reports/exams/{exam}/summary', [ReportController::class, 'examSummary']);
        Route::get('/reports/students/{student_user_id}/summary', [ReportController::class, 'studentSummary']);

        // Estrategias del tutor de un alumno. El docente solo ve las nacidas de
        // exámenes suyos; el chat con el tutor no sale por aquí nunca ([175]).
        Route::get('/reports/students/{student_user_id}/strategies', [ReportController::class, 'studentStrategies']);
        Route::get('/reports/ai/tutor-usage', [ReportController::class, 'tutorUsage']);

        // Analíticas agregadas
        Route::get('/analytics/institution', [AnalyticsController::class, 'institution']);
        Route::get('/analytics/subjects', [AnalyticsController::class, 'subjects']);
        Route::get('/analytics/students/{student_user_id}', [AnalyticsController::class, 'student']);
    });

    /*
    |--------------------------------------------------------------------------
    | SOLO SUPERADMIN — el operador de la plataforma, externo a las instituciones
    |--------------------------------------------------------------------------
    |
    | Dos competencias y ninguna más: dar de alta instituciones y darles su
    | administrador. No tiene una sola ruta hacia datos académicos, y tampoco
    | podría usarlas: al no pertenecer a ninguna institución nunca hay
    | `tenant_id`, y los modelos con `TenantScoped` lo exigen.
    |
    | Antes `/institutions` vivía bajo `role:admin` sin filtrar por institución,
    | así que el administrador de un centro listaba todos los del SaaS.
    */
    Route::middleware('role:superadmin')->group(function () {

        Route::get('/institutions', [InstitutionController::class, 'index']);
        Route::post('/institutions', [InstitutionController::class, 'store']);
        Route::get('/institutions/{institution}', [InstitutionController::class, 'show']);
        Route::put('/institutions/{institution}', [InstitutionController::class, 'update']);
        Route::patch('/institutions/{institution}/toggle', [InstitutionController::class, 'toggleStatus']);

        // ⚠️ Irreversible: arrastra en cascada TODOS los datos del centro
        // (estudiantes, exámenes, intentos, resultados) y borra sus cuentas.
        // Ver el docblock de `InstitutionController::destroy()`.
        Route::delete('/institutions/{institution}', [InstitutionController::class, 'destroy']);

        // Administradores de institución. El alta cuelga de la institución
        // porque un admin no existe fuera de un centro.
        Route::post('/institutions/{institution}/admins', [InstitutionAdminController::class, 'store']);

        Route::get('/institution-admins', [InstitutionAdminController::class, 'index']);
        Route::get('/institution-admins/{institutionAdmin}', [InstitutionAdminController::class, 'show']);
        Route::put('/institution-admins/{institutionAdmin}', [InstitutionAdminController::class, 'update']);
        Route::patch('/institution-admins/{institutionAdmin}/status', [InstitutionAdminController::class, 'setStatus']);
        Route::patch('/institution-admins/{institutionAdmin}/reset-password', [InstitutionAdminController::class, 'resetPassword']);
        Route::delete('/institution-admins/{institutionAdmin}', [InstitutionAdminController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | SOLO ADMIN DE INSTITUCIÓN — gestión interna del centro
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {

        // Alta de usuarios y gestión de cuentas — solo admin (en su institución)
        Route::post('/register', [AuthController::class, 'register'])->name('register');

        // Carga masiva: crea cuentas de usuario, por eso es admin-only
        Route::get('/students/bulk-upload/template', [StudentController::class, 'bulkUploadTemplate']);
        Route::post('/students/bulk-upload', [StudentController::class, 'bulkUpload'])->middleware('throttle:bulk-upload');

        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/status', [UserController::class, 'setStatus']);
        Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        // Catálogo de materias: define la oferta académica de la institución y
        // borrar una cascadea a exámenes → preguntas → intentos → respuestas,
        // por eso TODAS sus mutaciones quedan reservadas al administrador.
        // La lectura (GET) sigue abierta a admin, teacher y student.
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::patch('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

        // Configuración del sistema — solo admin puede editar
        Route::put('/system/config', [SystemConfigController::class, 'update']);

        // Asignación docente → grupo → materia.
        //
        // Es la tabla de la que cuelga TODO el alcance de un docente («mis
        // estudiantes», informes, progreso, recomendaciones). Solo el admin la
        // toca: un docente que pudiera asignarse a sí mismo se concedería
        // acceso a cualquier grupo de la institución, que es justo lo que este
        // modelo viene a cerrar.
        Route::get('/teacher-assignments', [TeacherAssignmentController::class, 'index']);
        Route::post('/teacher-assignments', [TeacherAssignmentController::class, 'store']);
        Route::delete('/teacher-assignments/bulk', [TeacherAssignmentController::class, 'destroyBulk']);
        Route::delete('/teacher-assignments/{teacherAssignment}', [TeacherAssignmentController::class, 'destroy']);

        // Membresía de un grupo puntual (alta y baja lógica por lista de ids).
        // Admin-only por el mismo motivo: si un docente pudiera meter
        // estudiantes en el grupo que tiene asignado, ampliaría su propio
        // alcance por la otra punta de la cadena.
        // Para mover estudiantes ENTRE grupos usar /bulk/reassign-group, que
        // además cierra la membresía anterior y recuenta ambos grupos.
        Route::post('/groups/{group}/students', [GroupController::class, 'addStudents']);
        Route::delete('/groups/{group}/students', [GroupController::class, 'removeStudents']);

        // Reasignación masiva (promoción de fin de año, correcciones en bloque).
        // Toca membresías, contadores y campos denormalizados de cientos de
        // filas de una vez, por eso es admin-only y va con throttle.
        Route::post('/bulk/reassign-group', [BulkReassignmentController::class, 'reassignGroup'])
            ->middleware('throttle:bulk-ops');
        Route::post('/bulk/reassign-subjects', [BulkReassignmentController::class, 'reassignSubjects'])
            ->middleware('throttle:bulk-ops');
        Route::post('/bulk/reset-progress', [BulkReassignmentController::class, 'resetProgress'])
            ->middleware('throttle:bulk-ops');
    });

    // Configuración del sistema — admin y teacher pueden leer
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/system/config', [SystemConfigController::class, 'show']);
    });

    // Inscripción de materias (StudentSubject)
    // Ruta literal /me debe ir ANTES de la paramétrica /{student_user_id}
    Route::middleware('role:student')->get('/students/me/subjects', [StudentSubjectController::class, 'mySubjects']);
    Route::middleware('role:admin,teacher')->group(function () {
        Route::post('/students/{student_user_id}/subjects', [StudentSubjectController::class, 'enroll']);
        Route::delete('/students/{student_user_id}/subjects/{subject}', [StudentSubjectController::class, 'unenroll']);
        Route::get('/students/{student_user_id}/subjects', [StudentSubjectController::class, 'index']);
    });
});
