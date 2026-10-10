<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Jobs\EnviarEnlaceDeAlta;
use App\Enums\AdecuacionType;
use App\Enums\LearningStyle;
use App\Models\Academic\Group;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Services\Imports\BulkFileReader;
use App\Services\Imports\BulkPreview;
use App\Services\Imports\BulkTemplateService;
use App\Services\Students\StudentBulkImporter;
use App\Jobs\ProcesarCargaMasivaEstudiantes;
use App\Notifications\CargaMasivaEstudiantes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\RelacionesEnLinea;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class StudentController extends Controller
{
    use AcotaAlDocente;

    /** Campos de la ficha que solo el administrador modifica (el docente, no). */
    private const CAMPOS_SOLO_ADMIN = ['student_code', 'grade', 'section', 'group_code'];

    /** Campos que derivan de la matrícula: nadie los edita por la ficha. */
    private const CAMPOS_DE_MATRICULA = ['grade', 'section', 'group_code'];

    /*
     | Grados, secciones y limites de carga viven en `config/academic.php` y
     | `config/bulk.php`. Salieron de aqui porque describen el sistema
     | educativo de un pais concreto y la capacidad de un servidor concreto,
     | no reglas del programa.
     */

    // Columnas que muestra la plantilla. full_name/email son del USUARIO (tabla users)
    // y solo se usan para crear la cuenta; no se vuelcan al modelo Student.
    public function index(Request $request)
    {
        // `status` va a una columna enum: un valor ajeno era un 500 de PostgreSQL.
        $request->validate([
            'grade'   => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:20'],
            'status'  => ['nullable', Rule::in(array_map(fn ($e) => $e->value, StudentStatus::cases()))],
            'q'       => ['nullable', 'string', 'max:120'],
            // Por aula: los matriculados ahora en ella (`group_id`), los que NO lo
            // están (`exclude_group_id`, para elegir a quién matricular) o los que
            // no están en ninguna (`unassigned`).
            'group_id'         => ['nullable', 'uuid'],
            'exclude_group_id' => ['nullable', 'uuid'],
            'unassigned'       => ['nullable', 'boolean'],
        ]);

        // Del usuario solo lo que muestra un listado (nombre, correo, estado): sin
        // la fila entera (hash de contraseña, fechas…) por cada alumno.
        // El usuario en la MISMA consulta (LEFT JOIN, ver `RelacionesEnLinea`): con `with()` era
        // un viaje más a la base.
        $query = RelacionesEnLinea::unir(Student::query(), [
            'user' => ['users', 'user_id', ['id', 'full_name', 'email', 'status']],
        ])->orderBy('students.student_code');

        // Docente: solo el alumnado de los grupos que tiene asignados. Sin esto
        // devolvía el padrón completo de la institución a cualquier docente.
        $this->acotarAEstudiantesDelDocente($query, $request->user(), 'user_id');

        if ($request->filled('grade')) {
            $query->where('students.grade', (int) $request->input('grade'));
        }

        if ($request->filled('section')) {
            $query->where('students.section', strtoupper($request->string('section')->toString()));
        }

        if ($request->filled('status')) {
            $query->where('students.status', $request->string('status')->toString());
        }

        // Matrícula abierta (`left_at` nulo) en `group_students`, acotada al centro.
        $matriculados = fn () => \Illuminate\Support\Facades\DB::table('group_students')
            ->select('student_user_id')
            ->where('institution_id', $request->user()->institution_id)
            ->whereNull('left_at');

        if ($request->filled('group_id')) {
            $query->whereIn('user_id', $matriculados()->where('group_id', $request->input('group_id')));
        }

        if ($request->filled('exclude_group_id')) {
            $query->whereNotIn('user_id', $matriculados()->where('group_id', $request->input('exclude_group_id')));
        }

        if ($request->boolean('unassigned')) {
            $query->whereNotIn('user_id', $matriculados());
        }

        // Búsqueda por nombre, correo o código de estudiante. Los comodines de
        // LIKE (% y _) se buscan como texto, no como patrón.
        if ($request->filled('q')) {
            $q = addcslashes(trim($request->string('q')->toString()), '%_\\');
            $query->where(function ($w) use ($q) {
                $w->where('student_code', 'ilike', "%{$q}%")
                  ->orWhereIn('user_id', \App\Models\Admin\User::query()
                      ->select('id')
                      ->where('user_type', 'student')
                      ->where(fn ($u) => $u->where('full_name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")));
            });
        }

        $pagina = $this->paginar($query, $request);
        RelacionesEnLinea::hidratar($pagina->getCollection(), ['user' => \App\Models\Admin\User::class]);

        return response()->json([
            'data' => $pagina,
        ]);
    }

    public function show(string $student_user_id, Request $request)
    {
        // El alumno y su usuario en una sola consulta (`Student::conUsuario`).
        $student = $this->alumnoConUsuarioVisiblePor($request->user(), $student_user_id) ?? abort(404);

        if ($this->noAlcanzaAlAlumno($request->user(), $student)) {
            return $this->noAutorizadoPorAsignacion();
        }

        return response()->json([
            'data' => $student,
        ]);
    }

    public function update(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $student_user_id)) {
            return $this->noAutorizadoPorAsignacion();
        }

        // El docente edita el perfil y los datos de aprendizaje de SUS alumnos.
        // Curso, sección, código de estudiante y aula (`group_code`) son del
        // administrador, que es quien ubica al alumno en el centro. Se rechaza
        // en vez de ignorar en silencio: si no, el docente creería haberlo
        // cambiado. (El estado del alumno tiene su propia ruta, solo admin.)
        if ($this->esDocente($request->user())) {
            $soloAdmin = array_values(array_filter(
                self::CAMPOS_SOLO_ADMIN,
                fn (string $campo) => $request->has($campo)
            ));

            if (!empty($soloAdmin)) {
                return response()->json([
                    'message' => 'No autorizado: solo el administrador puede modificar ' . implode(', ', $soloAdmin) . '.',
                    'campos_solo_admin' => $soloAdmin,
                ], 403);
            }
        }

        // Curso, sección y aula salen de la matrícula (`group_students`), no se
        // escriben a mano: cambiarlos aquí dejaba la ficha diciendo «6B» con el
        // alumno matriculado —y visible para los docentes— en otra aula. Se
        // cambian moviéndolo de aula, que recalcula estos campos.
        $deMatricula = array_values(array_filter(
            self::CAMPOS_DE_MATRICULA,
            fn (string $campo) => $request->has($campo)
        ));

        if (!empty($deMatricula)) {
            return response()->json([
                'message' => 'El curso, la sección y el aula se cambian moviendo al estudiante de aula '
                    . '(Gestión académica), no editando la ficha.',
                'campos_de_matricula' => $deMatricula,
            ], 422);
        }

        $data = $request->validate([
            // Único dentro de la institución, igual que la constraint
            // `students_institucion_codigo_unique`. Sin esta regla, un código
            // repetido no daba un 422 sino un 500 al chocar contra la base.
            'student_code'   => [
                'sometimes', 'string', 'max:40',
                Rule::unique('students', 'student_code')
                    ->where('institution_id', $request->user()->institution_id)
                    ->ignore($student->user_id, 'user_id'),
            ],
            'birth_date'     => ['nullable', 'date'],
            'parent_name'    => ['nullable', 'string', 'max:120'],
            'parent_email'   => ['nullable', 'email', 'max:120'],
            'adecuacion_type'  => ['nullable', Rule::in(array_map(fn($c) => $c->value, AdecuacionType::cases()))],
            'learning_style'   => ['nullable', Rule::in(array_map(fn($c) => $c->value, LearningStyle::cases()))],
        ]);

        $student->fill($data);
        $student->save();

        return response()->json([
            'data' => $student->fresh()->load('user'),
        ]);
    }

    #[OA\Post(
        path: '/api/students/bulk-upload',
        summary: 'Carga masiva de estudiantes (CSV o XLSX)',
        description: 'Crea/actualiza perfiles de estudiante. Si la fila trae «email» y no '
            . 'existe el usuario, crea también la cuenta (en la institución del actor) y le '
            . 'envía un correo para que establezca su contraseña. Todos los datos quedan '
            . 'ligados a la institución del usuario autenticado.',
        tags: ['Students'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary',
                            description: 'Archivo CSV o XLSX. Máximo 5 MB y 5.000 filas.'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resultado de la importación'),
            new OA\Response(response: 422, description: 'Archivo inválido o supera límites'),
        ]
    )]
    public function bulkUpload(Request $request, BulkFileReader $reader, StudentBulkImporter $importer)
    {
        $maxKb = config('bulk.students.max_mb') * 1024;

        $request->validate([
            'file'    => ['required', 'file', "mimes:csv,txt,xlsx", "max:{$maxKb}"],
            // Vista previa: valida todo y no escribe nada (ver BulkPreview).
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        $simular = $request->boolean('dry_run');

        $file = $request->file('file');
        [$rows, $parseError] = $reader->read($file);

        if ($parseError) {
            return response()->json(['message' => $parseError], 422);
        }

        $totalRows = count($rows);

        if ($totalRows === 0) {
            return response()->json(['message' => 'El archivo no contiene filas de datos.'], 422);
        }

        if ($totalRows > config('bulk.students.max_rows')) {
            return response()->json([
                'message' => "El archivo excede el límite de " . config('bulk.students.max_rows') . " filas. Se encontraron {$totalRows} filas.",
            ], 422);
        }

        // Verificar que exista al menos una columna identificadora
        $firstRow = reset($rows);
        if (
            !array_key_exists('user_id', $firstRow) &&
            !array_key_exists('student_code', $firstRow) &&
            !array_key_exists('email', $firstRow)
        ) {
            return response()->json([
                'message' => 'El archivo debe contener al menos una columna identificadora: «Correo institucional» (para crear), «ID de usuario» o «Código de estudiante».',
            ], 422);
        }

        // El aula es obligatoria. Sin ella, la carga dejaba estudiantes con una
        // etiqueta de sección en la ficha pero sin matrícula en ningún grupo, y
        // desde el modelo de asignaciones eso los vuelve invisibles: no los ve
        // ningún docente, no reciben exámenes y no salen en informes.
        // Se identifica por «Sección» (lo que el administrador conoce, p. ej.
        // «6-1»). La columna «Aula» con el código del grupo sigue valiendo para
        // los archivos anteriores a la plantilla por sección.
        if (!array_key_exists('seccion', $firstRow) && !array_key_exists('aula', $firstRow)) {
            return response()->json([
                'message' => 'El archivo debe contener la columna «Sección» con la sección de cada estudiante '
                    . '(por ejemplo 6-1). Descarga la plantilla actualizada.',
            ], 422);
        }

        // Sin aulas no hay dónde matricular a nadie: se avisa ya, no al terminar.
        if (!Group::query()->exists()) {
            return response()->json([
                'message' => 'Tu institución todavía no tiene aulas. '
                    . 'Créalas primero en Académico → Estructura antes de cargar estudiantes.',
            ], 422);
        }

        $institutionId = $request->user()->institution_id;

        // La vista previa es solo lectura y rápida (sin escrituras): se responde
        // en la misma petición.
        if ($simular) {
            return response()->json($importer->importar($rows, $institutionId, true));
        }

        // La carga real se hace en segundo plano. Procesar cientos de filas
        // contra una base remota supera cualquier timeout de navegador o proxy,
        // así que aquí solo se recibe y se acepta; el resultado llega como aviso
        // en la app (campana) y se puede consultar con
        // GET /api/students/bulk-upload/{import_id}.
        $aviso = new CargaMasivaEstudiantes(totalRows: $totalRows);
        $aviso->id = (string) Str::uuid();
        $request->user()->notify($aviso);

        ProcesarCargaMasivaEstudiantes::dispatch(
            $request->user()->id,
            $institutionId,
            $aviso->id,
            $rows,
        );

        return response()->json([
            'status'      => CargaMasivaEstudiantes::EN_COLA,
            'import_id'   => $aviso->id,
            'total_rows'  => $totalRows,
            'message'     => 'Archivo recibido. La carga se está procesando; te avisaremos cuando esté lista.',
        ]);
    }

    /**
     * GET /api/students/bulk-upload/{import_id} — estado y resultado de una
     * carga en segundo plano. Solo la ve quien la subió: se busca entre SUS
     * avisos, igual que el resto de notificaciones.
     */
    public function bulkUploadStatus(Request $request, string $import_id)
    {
        $aviso = $request->user()->notifications()
            ->where('id', $import_id)
            ->where('type', CargaMasivaEstudiantes::TIPO)
            ->firstOrFail();

        return response()->json(['id' => $aviso->id] + $aviso->data);
    }

    #[OA\Get(
        path: '/api/students/bulk-upload/template',
        summary: 'Descargar plantilla de carga masiva (CSV con ";" o XLSX)',
        tags: ['Students'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx'], default: 'csv')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Archivo de plantilla'),
        ]
    )]
    public function bulkUploadTemplate(Request $request, BulkTemplateService $templates)
    {
        $format = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ])['format'] ?? 'csv';

        return $templates->download(UserType::Student->value, $format);
    }

    /**
     * POST /api/students/{student_user_id}/reset-password
     *
     * El docente (de SUS alumnos) o el administrador restablece la contraseña de
     * un estudiante. El sistema genera una temporal aleatoria y la manda al
     * correo del estudiante; **quien lo pide nunca la ve**. El estudiante entra
     * con ella y el frontend le exige elegir una propia.
     *
     * Aparte de `PATCH /users/{user}/reset-password` (solo admin, que sí fija una
     * clave escrita a mano): aquí el docente no elige ni conoce nada.
     */
    public function resetPassword(Request $request, string $student_user_id)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $student_user_id)) {
            return $this->noAutorizadoPorAsignacion();
        }

        $cuenta = $student->user;

        if ($cuenta === null || $cuenta->user_type !== UserType::Student) {
            abort(404);
        }

        // A una cuenta suspendida no se le entrega acceso: la bloqueó un administrador.
        if ($cuenta->status === UserStatus::Suspended) {
            return response()->json([
                'message' => 'La cuenta del estudiante está suspendida. Un administrador debe reactivarla antes.',
            ], 422);
        }

        // La marca primero: `EnviarEnlaceDeAlta` no toca una cuenta activa sin ella.
        $cuenta->forceFill(['must_change_password' => true])->save();
        EnviarEnlaceDeAlta::dispatch($cuenta->id);

        return response()->json([
            'message' => 'Enviamos una contraseña temporal al correo del estudiante. Deberá cambiarla al iniciar sesión.',
            // Enmascarado: confirma a dónde fue sin repartir el correo completo.
            'sent_to' => preg_replace('/^(.).*(@.*)$/u', '$1•••$2', $cuenta->email),
        ]);
    }

    public function setStatus(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $student_user_id)) {
            return $this->noAutorizadoPorAsignacion();
        }

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

    public function me(Request $request)
    {
        $user    = $request->user();
        $student = Student::with('user')->where('user_id', $user->id)->first();

        // Degradación elegante: si no hay perfil (estado que no debería darse),
        // se responde 200 con data:null en vez de 404, para que el frontend
        // pueda manejarlo sin quedarse en blanco.
        return response()->json([
            'data'           => $student,
            'has_profile'    => $student !== null,
        ]);
    }

    /**
     * GET /students/me/attempts — los exámenes que el estudiante ya entregó,
     * con su nota. Es su historial: lo que ve en «Resultados».
     *
     * Solo intentos entregados (`submitted_at`): uno en curso no tiene nota.
     * `review_available` sigue la misma regla que `ExamAttemptController::show`
     * —el docente decide con `allow_review_after_submission` si se pueden
     * revisar las respuestas—; la nota en sí ya se le muestra al entregar.
     */
    public function myAttempts(Request $request)
    {
        $attempts = ExamAttempt::query()
            ->where('student_user_id', $request->user()->id)
            ->whereNotNull('submitted_at')
            ->with(['exam:id,title,subject_id,allow_review_after_submission', 'exam.subject:id,name'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'id'               => $a->id,
                'exam_id'          => $a->exam_id,
                'exam_title'       => $a->exam?->title,
                'subject'          => $a->exam?->subject?->name,
                'attempt_number'   => $a->attempt_number,
                'submitted_at'     => $a->submitted_at,
                'score'            => (float) $a->score,
                'max_score'        => (float) $a->max_score,
                'percentage'       => $a->percentage,
                'grade_status'     => $a->grade_status,
                'review_available' => (bool) $a->exam?->allow_review_after_submission,
            ]);

        return response()->json(['data' => $attempts]);
    }
    public function availableExams(Request $request)
    {
        $user = $request->user();

        // La regla de «examen visible para este alumno» (activo, vigente y
        // asignado a sus grupos) vive en `Exam::scopeVisibleTo`, que es la que
        // aplican también `/exams` y `/exams/{id}`. Aquí solo se añade lo propio
        // de la disponibilidad: que le queden intentos.
        //
        // withCount mueve el filtro de intentos a la BD: elimina la query separada
        // y el filtrado en memoria sobre colecciones potencialmente grandes.
        $exams = Exam::query()
            ->visibleTo($user)
            ->withCount(['attempts as submitted_count' => fn($q) =>
                $q->where('student_user_id', $user->id)->whereNotNull('submitted_at')
            ])
            ->with('subject')
            ->get()
            ->filter(fn($e) => $e->submitted_count < $e->max_attempts)
            ->values();

        return response()->json(['data' => $exams]);
    }
}
