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

// Metadatos compartidos con el comando `openapi:generate`.
require $root . '/vendor/autoload.php';

use App\Support\ApiSpec;

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
$skip = ApiSpec::skippedUris();

/* {param} de la URI -> nombre de variable de colección. */
$paramMap = ApiSpec::paramNames();

/* Rutas públicas (sin Bearer). */
$publicUris = ApiSpec::publicUris();

/* -------------------------------------------------------------------------
 | Cuerpos de ejemplo. Clave = "METODO uri" (uri tal cual la da route:list).
 | Valores basados en los validate() reales de cada controlador.
 * ------------------------------------------------------------------------*/
$bodies = ApiSpec::exampleBodies();

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
$folderNames = ApiSpec::folders();

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
