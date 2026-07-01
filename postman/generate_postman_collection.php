<?php

/*
|--------------------------------------------------------------------------
| Generador de colección Postman — NeoEduCore
|--------------------------------------------------------------------------
| Lee la lista REAL de rutas de la API (php artisan route:list --json) y
| genera una colección Postman v2.1 con TODAS y CADA UNA de las rutas,
| agrupadas por módulo, con:
|   - Autenticación Bearer {{token}} heredada a nivel de colección.
|   - Cuerpos (body) de ejemplo por endpoint (basados en los validate()).
|   - Scripts que guardan automáticamente el token al hacer login y capturan
|     ids (exam_id, subject_id, group_id, ...) desde los listados.
|
| Uso:
|   php postman/generate_postman_collection.php
|
| Genera:  postman/NeoEduCore.postman_collection.json
|
| Como lee route:list en cada ejecución, la colección NO se desincroniza:
| si agregas o quitas una ruta, vuelve a correr el script y listo.
*/

$root = dirname(__DIR__);
chdir($root);

$phpBin = PHP_BINARY;
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'artisan') . ' route:list --json';
$raw = shell_exec($cmd);

$routes = json_decode((string) $raw, true);
if (!is_array($routes)) {
    fwrite(STDERR, "ERROR: no se pudo obtener la lista de rutas. ¿Está bien instalado el proyecto?\n");
    fwrite(STDERR, (string) $raw . "\n");
    exit(1);
}

/* Rutas de infraestructura que NO son de la API funcional. */
$skip = ['api/documentation', 'api/oauth2-callback'];

/* {param} de la URI -> nombre de variable de colección. */
$paramMap = [
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

/* Rutas públicas (sin Bearer). */
$publicUris = [
    'api/ping',
    'api/auth/login',
    'api/password/forgot',
    'api/password/verify',
    'api/password/reset',
];

/* -------------------------------------------------------------------------
 | Cuerpos de ejemplo. Clave = "METODO uri" (uri tal cual la da route:list).
 | Valores basados en los validate() reales de cada controlador.
 * ------------------------------------------------------------------------*/
$bodies = [
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

/* Endpoints de subida de archivo (body form-data en lugar de JSON). */
$fileUploads = [
    'POST api/students/bulk-upload' => 'file',
];

/* -------------------------------------------------------------------------
 | Scripts de captura de ids desde listados. Clave = "METODO uri".
 * ------------------------------------------------------------------------*/
$captureHelper = <<<'JS'
function neoFirst(body){
  var d = (body && body.data !== undefined) ? body.data : body;
  if (d && d.data !== undefined && Array.isArray(d.data)) d = d.data; // paginador Laravel
  if (Array.isArray(d)) return d[0] || null;
  return null;
}
JS;

$captures = [
    'GET api/exams' => ['exam_id', 'id'],
    'GET api/subjects' => ['subject_id', 'id'],
    'GET api/groups' => ['group_id', 'id'],
    'GET api/students' => ['student_user_id', 'user_id'],
    'GET api/users' => ['user_id', 'id'],
    'GET api/institutions' => ['institution_id', 'id'],
    'GET api/study-resources' => ['study_resource_id', 'id'],
    'GET api/calendar-events' => ['calendar_event_id', 'id'],
    'GET api/exams/{exam}/questions' => ['question_id', 'id'],
    'GET api/ai-recommendations' => ['ai_recommendation_id', 'id'],
    'GET api/ai/tutor/sessions' => ['session_id', 'id'],
    'GET api/exam-attempts/{attempt}/answers' => ['student_answer_id', 'id'],
];

/* Script de login: guarda token + institution_id. */
$loginScript = <<<'JS'
var res = pm.response.json();
if (res && res.token) {
  pm.collectionVariables.set('token', res.token);
  console.log('Token guardado en {{token}}');
}
if (res && res.user && res.user.institution_id) {
  pm.collectionVariables.set('institution_id', res.user.institution_id);
}
JS;

/* -------------------------------------------------------------------------
 | Nombres bonitos de carpetas por primer segmento tras "api/".
 * ------------------------------------------------------------------------*/
$folderNames = [
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

/* Elige el método "real" de una cadena "GET|HEAD" o "PUT|PATCH". */
function pickMethod(string $methods): string
{
    $parts = explode('|', $methods);
    $parts = array_values(array_filter($parts, fn($m) => strtoupper($m) !== 'HEAD'));
    return strtoupper($parts[0] ?? 'GET');
}

/* Convierte la uri a segmentos Postman, reemplazando {param} por {{var}}. */
function toPostmanPath(string $uri, array $paramMap): array
{
    $segments = explode('/', $uri);
    return array_map(function ($seg) use ($paramMap) {
        if (preg_match('/^\{(.+?)\??\}$/', $seg, $m)) {
            $var = $paramMap[$m[1]] ?? $m[1];
            return '{{' . $var . '}}';
        }
        return $seg;
    }, $segments);
}

/* Ordena las rutas para que dentro de cada carpeta salgan agrupadas. */
usort($routes, fn($a, $b) => strcmp($a['uri'], $b['uri']));

$folders = [];      // name => items[]
$folderOrder = [];  // preserva el orden de aparición

foreach ($routes as $r) {
    $uri = $r['uri'];
    if (!str_starts_with($uri, 'api/')) continue;
    if (in_array($uri, $skip, true)) continue;

    $method = pickMethod($r['method']);
    $key = $method . ' ' . $uri;

    $segAfter = explode('/', $uri)[1] ?? '';
    $folder = $folderNames[$segAfter] ?? ('99 · ' . ucfirst($segAfter));

    $isPublic = in_array($uri, $publicUris, true);

    // URL
    $path = toPostmanPath($uri, $paramMap);
    $request = [
        'method' => $method,
        'header' => [
            ['key' => 'Accept', 'value' => 'application/json'],
        ],
        'url' => [
            'raw' => '{{base_url}}/' . implode('/', $path),
            'host' => ['{{base_url}}'],
            'path' => $path,
        ],
    ];

    // Body
    if (isset($fileUploads[$key])) {
        $request['body'] = [
            'mode' => 'formdata',
            'formdata' => [
                ['key' => $fileUploads[$key], 'type' => 'file', 'src' => []],
            ],
        ];
    } elseif (array_key_exists($key, $bodies)) {
        $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $json = json_encode($bodies[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $request['body'] = [
            'mode' => 'raw',
            'raw' => $json,
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    // Auth override para rutas públicas
    if ($isPublic) {
        $request['auth'] = ['type' => 'noauth'];
    }

    $item = [
        'name' => $method . ' /' . $uri,
        'request' => $request,
        'response' => [],
    ];

    // Eventos (test scripts)
    $events = [];
    if ($uri === 'api/auth/login') {
        $events[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $loginScript)]];
    } elseif (isset($captures[$key])) {
        [$var, $field] = $captures[$key];
        $js = $captureHelper . "\nvar item = neoFirst(pm.response.json());\n"
            . "if (item && item['{$field}']) { pm.collectionVariables.set('{$var}', item['{$field}']); console.log('{$var} =', item['{$field}']); }";
        $events[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $js)]];
    }
    if ($events) $item['event'] = $events;

    if (!isset($folders[$folder])) {
        $folders[$folder] = [];
        $folderOrder[] = $folder;
    }
    $folders[$folder][] = $item;
}

sort($folderOrder);

$folderItems = [];
foreach ($folderOrder as $name) {
    $folderItems[] = ['name' => $name, 'item' => $folders[$name]];
}

$collection = [
    'info' => [
        'name' => 'NeoEduCore API',
        '_postman_id' => '00000000-0000-4000-8000-000000000001',
        'description' => "Colección generada automáticamente desde php artisan route:list.\n\n"
            . "Cómo usar:\n"
            . "1. Importa también el environment NeoEduCore.postman_environment.json y selecciónalo.\n"
            . "2. Ejecuta '01 · Auth y Sesión > POST /api/auth/login' (por defecto entra como admin). Guarda el token solo.\n"
            . "3. Corre los listados (GET) para autocapturar ids (exam_id, subject_id, ...).\n"
            . "4. Para probar como otro rol, cambia admin_email/admin_password del body de login por los de teacher/student y repite.\n\n"
            . "Credenciales del seeder (php artisan db:seed): admin@neoeducore.edu.co / profesor1@neoeducore.edu.co / estudiante1@neoeducore.edu.co — todas con password 'password123'.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'item' => $folderItems,
    'variable' => [
        ['key' => 'base_url', 'value' => 'http://localhost:8000/api'],
        ['key' => 'token', 'value' => ''],
    ],
];

$out = $root . DIRECTORY_SEPARATOR . 'postman' . DIRECTORY_SEPARATOR . 'NeoEduCore.postman_collection.json';
file_put_contents($out, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$count = array_sum(array_map('count', $folders));
echo "OK: colección generada con {$count} endpoints en " . count($folderItems) . " carpetas.\n";
echo "Archivo: {$out}\n";
