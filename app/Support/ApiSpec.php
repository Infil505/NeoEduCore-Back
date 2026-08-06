<?php

namespace App\Support;

/**
 * Metadatos compartidos por los dos generadores de documentación de la API:
 * `postman/generate_postman_collection.php` y el comando `openapi:generate`.
 *
 * Ambos leen las rutas reales con `route:list`, así que la lista de endpoints
 * nunca se desincroniza sola. Lo que sí se desincronizaría es todo lo que no
 * se puede deducir de una ruta —el nombre bonito del módulo, si el endpoint es
 * público, un cuerpo de ejemplo— si cada generador llevara su propia copia.
 * Por eso vive aquí una sola vez.
 *
 * Al añadir un endpoint nuevo: si necesita cuerpo de ejemplo, añádelo a
 * `exampleBodies()`; el resto se deduce de la ruta y su middleware.
 */
final class ApiSpec
{
    /** Rutas de infraestructura que no forman parte de la API funcional. */
    public static function skippedUris(): array
    {
        return ['api/documentation', 'api/oauth2-callback'];
    }

    /** `{param}` de la URI → nombre legible del identificador. */
    public static function paramNames(): array
    {
        return [
        'exam'            => 'exam_id',
        'attempt'         => 'attempt_id',
        'subject'         => 'subject_id',
        'study_resource'  => 'study_resource_id',
        'calendar_event'  => 'calendar_event_id',
        'aiRecommendation' => 'ai_recommendation_id',
        'group'           => 'group_id',
        'question'        => 'question_id',
        'user'            => 'user_id',
        'institution'     => 'institution_id',
        'student_user_id' => 'student_user_id',
        'studentAnswer'   => 'student_answer_id',
        'sessionId'       => 'session_id',
        ];
    }

    /** Rutas que no exigen token. */
    public static function publicUris(): array
    {
        return [
        'api/ping',
        'api/auth/login',
        'api/password/forgot',
        'api/password/verify',
        'api/password/reset',
        ];
    }

    /** Módulo (carpeta en Postman, tag en OpenAPI) por primer segmento tras `api/`. */
    public static function folders(): array
    {
        return [
        'ping' => '00 · Health',
        'auth' => '01 · Auth y Sesión',
        'password' => '01 · Auth y Sesión',
        'register' => '01 · Auth y Sesión',
        'users' => '02 · Usuarios (admin)',
        'institutions' => '03 · Instituciones (admin)',
        'students' => '04 · Estudiantes',
        'groups' => '05 · Grupos',
        'subjects' => '06 · Materias',
        'exams' => '07 · Exámenes, Preguntas e Intentos',
        'questions' => '07 · Exámenes, Preguntas e Intentos',
        'exam-attempts' => '07 · Exámenes, Preguntas e Intentos',
        'student-answers' => '07 · Exámenes, Preguntas e Intentos',
        'student-progress' => '08 · Progreso',
        'study-resources' => '09 · Recursos de estudio',
        'calendar-events' => '10 · Calendario',
        'ai' => '11 · IA (Tutor y Generación)',
        'ai-recommendations' => '11 · IA (Tutor y Generación)',
        'reports' => '12 · Reportes',
        'analytics' => '13 · Analíticas',
        'system' => '14 · Configuración del sistema',
        ];
    }

    /**
     * Cuerpos de ejemplo por endpoint. Clave = "METODO uri" tal cual la
     * devuelve `route:list`. Basados en los `validate()` reales.
     */
    public static function exampleBodies(): array
    {
        return [
        'POST api/auth/login' => [
            'email' => '{{admin_email}}',
            'password' => '{{admin_password}}',
            '_comment' => 'Para entrar como profesor/estudiante cambia por {{teacher_email}}/{{teacher_password}} o {{student_email}}/{{student_password}}.',
        ],
        'POST api/register' => [
            'full_name' => 'Estudiante de Prueba',
            'email' => 'nuevo.estudiante@neoeducore.edu.co',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'user_type' => 'student',
        ],
        'POST api/password/forgot' => ['email' => 'estudiante1@neoeducore.edu.co'],
        'POST api/password/verify' => ['email' => 'estudiante1@neoeducore.edu.co', 'token' => '{{reset_token}}'],
        'POST api/password/reset' => [
            'email' => 'estudiante1@neoeducore.edu.co',
            'token' => '{{reset_token}}',
            'password' => 'NuevaPass123',
            'password_confirmation' => 'NuevaPass123',
        ],
        'POST api/password/change' => [
            'current_password' => 'password123',
            'password' => 'NuevaPass123',
            'password_confirmation' => 'NuevaPass123',
        ],

        // Materias
        'POST api/subjects' => ['name' => 'Ciencias Naturales'],
        'PUT api/subjects/{subject}' => ['name' => 'Ciencias Naturales (editado)'],
        'PATCH api/subjects/{subject}' => ['name' => 'Ciencias Naturales (editado)'],

        // Grupos
        'POST api/groups' => ['name' => '10-B', 'grade' => 10, 'section' => 'B', 'year' => 2026, 'group_code' => '10B2026'],
        'PUT api/groups/{group}' => ['name' => '10-B (editado)', 'grade' => 10, 'section' => 'B', 'year' => 2026],

        // Exámenes
        'POST api/exams' => [
            'title' => 'Parcial 2 - Matemáticas',
            'subject_id' => '{{subject_id}}',
            'grade' => 10,
            'instructions' => 'Responder todas las preguntas.',
            'duration_minutes' => 60,
            'max_attempts' => 2,
            'show_results_immediately' => true,
            'allow_review_after_submission' => true,
            'randomize_questions' => false,
            'group_ids' => ['{{group_id}}'],
        ],
        'PUT api/exams/{exam}' => ['title' => 'Parcial 2 - Matemáticas (editado)', 'duration_minutes' => 90],
        'PATCH api/exams/{exam}' => ['status' => 'published', '_comment' => 'draft->published->active->completed. PATCH/PUT también aceptan los mismos campos que POST.'],

        // Preguntas
        'POST api/exams/{exam}/questions' => [
            'question_text' => '¿Cuánto es 5 x 3?',
            'question_type' => 'multiple_choice',
            'points' => 1,
            'order_index' => 1,
            'options' => [
                ['option_index' => 1, 'option_text' => '8', 'is_correct' => false],
                ['option_index' => 2, 'option_text' => '15', 'is_correct' => true],
                ['option_index' => 3, 'option_text' => '18', 'is_correct' => false],
                ['option_index' => 4, 'option_text' => '10', 'is_correct' => false],
            ],
        ],
        'PUT api/questions/{question}' => [
            'question_text' => '¿Cuánto es 5 x 3? (editado)',
            'points' => 2,
            'options' => [
                ['option_index' => 1, 'option_text' => '8', 'is_correct' => false],
                ['option_index' => 2, 'option_text' => '15', 'is_correct' => true],
                ['option_index' => 3, 'option_text' => '18', 'is_correct' => false],
                ['option_index' => 4, 'option_text' => '10', 'is_correct' => false],
            ],
        ],

        // Intento de examen
        'POST api/exams/{exam}/attempts/start' => [],
        'POST api/exams/{exam}/attempts/{attempt}/submit' => [
            'answers' => [
                ['question_id' => '{{question_id}}', 'selected_option_ids' => [], 'answer_text' => null],
            ],
            '_comment' => 'selected_option_ids son los IDs enteros (bigserial) de question_options, no el option_index. Para short_answer/essay usa answer_text.',
        ],
        'PATCH api/exams/{exam}/attempts/{attempt}/pause' => [],
        'PATCH api/exams/{exam}/attempts/{attempt}/resume' => [],
        'POST api/exam-attempts/{attempt}/recommendations/regenerate' => [],

        // Progreso
        'POST api/student-progress' => ['student_user_id' => '{{student_user_id}}', 'subject_id' => '{{subject_id}}', 'mastery_percentage' => 82.5],
        'POST api/student-progress/recalc' => ['student_user_id' => '{{student_user_id}}', 'subject_id' => '{{subject_id}}'],

        // Estudiantes
        'PUT api/students/{student_user_id}' => [
            'student_code' => 'STU-EDIT-001',
            'grade' => 10,
            'section' => 'A',
            'birth_date' => '2010-05-14',
            'parent_name' => 'Acudiente Ejemplo',
            'parent_email' => 'acudiente@mail.com',
            'group_code' => '10A2026',
            'adecuacion_type' => null,
            'learning_style' => 'visual',
        ],
        'PATCH api/students/{student_user_id}/status' => ['status' => 'active'],

        // Respuestas
        'PATCH api/student-answers/{studentAnswer}/review' => ['is_correct' => true, 'points_awarded' => 1, 'explanation' => 'Respuesta correcta.'],

        // Inscripción a materias
        'POST api/students/{student_user_id}/subjects' => ['subject_id' => '{{subject_id}}'],

        // Recursos de estudio
        'POST api/study-resources' => [
            'title' => 'Repaso de Fracciones',
            'description' => 'Video introductorio',
            'resource_type' => 'video',
            'url' => 'https://www.youtube.com/watch?v=ejemplo',
            'estimated_duration' => 20,
            'difficulty' => 'basic',
            'grade_min' => 9,
            'grade_max' => 11,
            'language' => 'es',
            '_comment' => 'La URL debe pertenecer a un dominio de la whitelist en config/ai_resources.php.',
        ],
        'PUT api/study-resources/{study_resource}' => ['title' => 'Repaso de Fracciones (editado)', 'difficulty' => 'intermediate'],
        'PATCH api/study-resources/{study_resource}' => ['title' => 'Repaso de Fracciones (editado)', 'difficulty' => 'intermediate'],

        // Calendario
        'POST api/calendar-events' => [
            'title' => 'Repaso general',
            'description' => 'Sesión de refuerzo',
            'start_at' => '2026-07-15T08:00:00Z',
            'end_at' => '2026-07-15T10:00:00Z',
            'event_type' => 'activity',
            'group_id' => '{{group_id}}',
        ],
        'PUT api/calendar-events/{calendar_event}' => ['title' => 'Repaso general (editado)'],
        'PATCH api/calendar-events/{calendar_event}' => ['title' => 'Repaso general (editado)'],

        // IA
        'POST api/ai/generate' => [
            'student_user_id' => '{{student_user_id}}',
            'subject_id' => '{{subject_id}}',
            'exam_id' => null,
            'type' => 'action',
            'prompt' => 'Genera una recomendación breve de estudio.',
        ],
        'POST api/ai/tutor/chat' => ['message' => 'Explícame qué es una fracción.', 'session_id' => null, 'subject_id' => null, 'mode' => 'explain', 'topic' => 'fracciones'],
        'PATCH api/ai/tutor/sessions/{sessionId}/end' => [],

        // Usuarios
        'PUT api/users/{user}' => ['full_name' => 'Nombre Editado', 'email' => 'editado@neoeducore.edu.co', 'status' => 'active'],
        'PATCH api/users/{user}/status' => ['status' => 'active'],
        'PATCH api/users/{user}/reset-password' => ['password' => 'NuevaPass123', 'password_confirmation' => 'NuevaPass123'],

        // Instituciones
        'PUT api/institutions/{institution}' => ['name' => 'Institución Editada', 'address' => 'Calle 123', 'phone' => '3100000000', 'email' => 'info@neoeducore.edu.co', 'is_active' => true],
        'PATCH api/institutions/{institution}/toggle' => [],

        // Configuración del sistema
        'PUT api/system/config' => [
            'timezone' => 'America/Bogota',
            'language' => 'es',
            'max_exam_duration' => 180,
            'allow_registration' => false,
            'contact_email' => 'contacto@neoeducore.edu.co',
        ],
        ];
    }

    /** Módulo al que pertenece una URI. */
    public static function folderFor(string $uri): string
    {
        $seg = explode('/', $uri)[1] ?? '';

        return self::folders()[$seg] ?? ('99 · ' . ucfirst($seg));
    }

    /** Elige el método "real" de una cadena "GET|HEAD" o "PUT|PATCH". */
    public static function pickMethod(string $methods): string
    {
        $parts = array_values(array_filter(
            explode('|', $methods),
            fn ($m) => strtoupper($m) !== 'HEAD'
        ));

        return strtoupper($parts[0] ?? 'GET');
    }
}
