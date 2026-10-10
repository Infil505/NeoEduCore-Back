<?php
// Auditoría de consultas contra la base LOCAL neoeducoreperf (nunca la remota).
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'localhost', 'DB_PORT' => '5432', 'DB_DATABASE' => 'neoeducoreperf',
          'DB_USERNAME' => 'postgres', 'DB_PERSISTENT' => 'false', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array',
          'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'DB_STATEMENT_TIMEOUT_MS' => '0'] as $k => $v) {
    putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
}
$pass = getenv('PERF_DB_PASSWORD'); putenv("DB_PASSWORD=$pass"); $_ENV['DB_PASSWORD'] = $pass; $_SERVER['DB_PASSWORD'] = $pass;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

echo "base: " . DB::selectOne('select current_database() d')->d . "\n";

$inst = '00000000-0000-4000-8000-000000000001';
app()->instance('tenant_id', $inst);
$admin = User::where('email', 'admin@perf.test')->first();
$teacher = User::where('email', 'docente1@perf.test')->first();
$student = User::where('email', 'alumno1@perf.test')->first();
$ids = [
    'group'   => DB::table('group_students')->where('student_user_id', $student->id)->value('group_id'),
    'exam'    => DB::table('exams')->where('created_by_teacher_id', $teacher->id)->where('status', 'completed')->value('id'),
    'subject' => DB::table('subjects')->value('id'),
    'attempt' => DB::table('exam_attempts')->where('student_user_id', $student->id)->value('id'),
    'resource' => DB::table('study_resources')->value('id'),
    'event'   => DB::table('calendar_events')->value('id'),
    'student' => $student->id,
    'teacher' => $teacher->id,
];
$examDeAlumno = DB::table('exam_attempts')->where('student_user_id', $student->id)->value('exam_id');

$sustituir = function (string $uri) use ($ids, $examDeAlumno, $inst) {
    $map = [
        'exam' => $ids['exam'], 'exam_id' => $ids['exam'], 'group' => $ids['group'], 'group_id' => $ids['group'],
        'subject' => $ids['subject'], 'student_user_id' => $ids['student'], 'studentUserId' => $ids['student'],
        'user' => $ids['student'], 'teacherUserId' => $ids['teacher'], 'teacher_user_id' => $ids['teacher'],
        'attempt' => $ids['attempt'], 'attemptId' => $ids['attempt'], 'attempt_id' => $ids['attempt'],
        'study_resource' => $ids['resource'], 'calendar_event' => $ids['event'], 'studyResource' => $ids['resource'],
        'calendarEvent' => $ids['event'], 'institutionAdmin' => $ids['teacher'], 'institution' => $inst,
    ];
    $faltan = false;
    $url = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($map, &$faltan) {
        if (isset($map[$m[1]])) return $map[$m[1]];
        $faltan = true; return 'x';
    }, $uri);
    return $faltan ? null : $url;
};

$rutas = [];
foreach (Route::getRoutes() as $r) {
    if (!in_array('GET', $r->methods(), true) || !str_starts_with($r->uri(), 'api/')) continue;
    if (str_contains($r->uri(), 'documentation') || str_contains($r->uri(), 'broadcasting') || str_contains($r->uri(), 'oauth2')
        || str_contains($r->uri(), 'template') || str_contains($r->uri(), '.csv') || str_contains($r->uri(), '.xlsx')
        || str_contains($r->uri(), 'export') || str_contains($r->uri(), 'ping')) continue;
    $u = $sustituir($r->uri());
    if ($u) $rutas[$u] = true;
}
$rutas = array_keys($rutas);
// Variantes de listados con su uso real del front
foreach (['/api/students?per_page=20&page=1', '/api/students?per_page=20&q=Apellido12', '/api/users/directory?per_page=20',
          '/api/users?staff=1&per_page=100', '/api/exams?per_page=100', '/api/dashboard/staff-overview?include=summary,groups,subjects',
          '/api/dashboard/staff-overview?include=exams,analytics,subjects', '/api/calendar-events?per_page=100', '/api/study-resources?per_page=100'] as $v) {
    $rutas[] = $v;
}

$resultados = []; $lentas = [];
foreach (['admin' => $admin, 'docente' => $teacher, 'alumno' => $student] as $rol => $usuario) {
    foreach ($rutas as $url) {
        Sanctum::actingAs($usuario);
        app('auth')->forgetGuards();
        app()->instance('tenant_id', $inst);
        $run = function () use ($kernel, $url, $usuario) {
            Sanctum::actingAs($usuario);
            DB::flushQueryLog(); DB::enableQueryLog();
            $t = hrtime(true);
            $resp = $kernel->handle(Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
            $ms = (hrtime(true) - $t) / 1e6;
            $log = DB::getQueryLog(); DB::disableQueryLog();
            return [$resp, $ms, $log];
        };
        $run(); // calienta cachés
        [$resp, $ms, $log] = $run();
        if ($resp->getStatusCode() !== 200) continue;
        $sqlMs = array_sum(array_column($log, 'time'));
        $resultados[] = ['rol' => $rol, 'url' => $url, 'ms' => $ms, 'n' => count($log), 'sqlms' => $sqlMs, 'bytes' => strlen($resp->getContent())];
        foreach ($log as $q) {
            if ($q['time'] >= 3.0 && stripos(ltrim($q['query']), 'select') === 0) {
                $lentas[] = ['rol' => $rol, 'url' => $url, 'ms' => $q['time'], 'sql' => $q['query'], 'b' => $q['bindings']];
            }
        }
    }
}

usort($resultados, fn ($a, $b) => $b['ms'] <=> $a['ms']);
echo "\n=== PETICIONES MÁS LENTAS (con datos de volumen, caché caliente) ===\n";
printf("%-8s %7s %5s %8s %9s  %s\n", 'rol', 'ms', 'cons', 'sql ms', 'bytes', 'url');
foreach (array_slice($resultados, 0, 28) as $r) {
    printf("%-8s %7.1f %5d %8.1f %9d  %s\n", $r['rol'], $r['ms'], $r['n'], $r['sqlms'], $r['bytes'], substr($r['url'], 0, 80));
}
usort($resultados, fn ($a, $b) => $b['n'] <=> $a['n']);
echo "\n=== MÁS CONSULTAS ===\n";
foreach (array_slice($resultados, 0, 10) as $r) printf("%-8s %3d cons  %7.1f ms  %s\n", $r['rol'], $r['n'], $r['ms'], substr($r['url'], 0, 80));
usort($resultados, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
echo "\n=== MÁS BYTES ===\n";
foreach (array_slice($resultados, 0, 10) as $r) printf("%-8s %9d B  %s\n", $r['rol'], $r['bytes'], substr($r['url'], 0, 80));

// Consultas lentas distintas (por forma), con su plan
$vistas = [];
usort($lentas, fn ($a, $b) => $b['ms'] <=> $a['ms']);
echo "\n=== CONSULTAS ≥ 3 ms (formas distintas) con su plan ===\n";
foreach ($lentas as $q) {
    $forma = preg_replace('/\s+/', ' ', $q['sql']);
    if (isset($vistas[$forma])) continue;
    $vistas[$forma] = true;
    if (count($vistas) > 14) break;
    echo "\n[{$q['rol']}] {$q['url']}  → " . round($q['ms'], 1) . " ms\n  " . substr($forma, 0, 260) . "\n";
    try {
        $plan = DB::select('explain (analyze, format json) ' . $q['sql'], $q['b']);
        $json = json_decode($plan[0]->{'QUERY PLAN'}, true)[0]['Plan'];
        $nodos = [];
        $walk = function ($p) use (&$walk, &$nodos) {
            $nodos[] = $p['Node Type'] . (isset($p['Relation Name']) ? " [{$p['Relation Name']}]" : '') . (isset($p['Index Name']) ? " idx={$p['Index Name']}" : '')
                . ' filas=' . ($p['Actual Rows'] ?? '?') . (isset($p['Rows Removed by Filter']) ? " descartadas={$p['Rows Removed by Filter']}" : '');
            foreach ($p['Plans'] ?? [] as $c) $walk($c);
        };
        $walk($json);
        foreach (array_slice($nodos, 0, 8) as $n) echo "    · $n\n";
    } catch (\Throwable $e) { echo "    (sin plan: " . substr($e->getMessage(), 0, 80) . ")\n"; }
}
