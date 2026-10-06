<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\AdecuacionType;
use App\Enums\LearningStyle;
use App\Models\Academic\Group;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Jobs\EnviarEnlaceDeAlta;
use App\Services\Imports\BulkFileReader;
use App\Services\Imports\BulkPreview;
use App\Services\Imports\SimulacionRevertida;
use App\Services\Imports\BulkTemplateService;
use App\Services\Students\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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

    // Columnas de PERFIL (tabla students) que se vuelcan al modelo Student.
    // institution_id se ignora por seguridad (lo asigna TenantScoped desde el tenant).
    //
    // `grade`, `section` y `group_code` NO están: desde el 08/08/2026 se derivan
    // del aula. Si vinieran del archivo podrían contradecirla —«aula=11B2026,
    // section=A»— y la ficha volvería a desviarse de la matrícula real, que es
    // justo el problema que la columna `aula` viene a cerrar.
    private const ALLOWED_COLUMNS = [
        'user_id', 'student_code', 'status',
        'birth_date', 'parent_name', 'parent_email', 'adecuacion_type',
    ];

    // Columnas que muestra la plantilla. full_name/email son del USUARIO (tabla users)
    // y solo se usan para crear la cuenta; no se vuelcan al modelo Student.
    public function index(Request $request)
    {
        // `status` va a una columna enum: un valor ajeno era un 500 de PostgreSQL.
        $request->validate([
            'grade'   => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:20'],
            'status'  => ['nullable', Rule::in(array_map(fn ($e) => $e->value, StudentStatus::cases()))],
        ]);

        $query = Student::query()
            ->with('user')
            ->orderBy('student_code');

        // Docente: solo el alumnado de los grupos que tiene asignados. Sin esto
        // devolvía el padrón completo de la institución a cualquier docente.
        $this->acotarAEstudiantesDelDocente($query, $request->user(), 'user_id');

        if ($request->filled('grade')) {
            $query->where('grade', (int) $request->input('grade'));
        }

        if ($request->filled('section')) {
            $query->where('section', strtoupper($request->string('section')->toString()));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return response()->json([
            'data' => $query->paginate(config('pagination.default')),
        ]);
    }

    public function show(string $student_user_id, Request $request)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $student_user_id)) {
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
    public function bulkUpload(Request $request, BulkFileReader $reader, EnrollmentService $enrollment)
    {
        $maxKb = config('bulk.students.max_mb') * 1024;

        $request->validate([
            'file'    => ['required', 'file', "mimes:csv,txt,xlsx", "max:{$maxKb}"],
            // Vista previa: se procesa todo y se deshace (ver BulkPreview).
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
        if (!array_key_exists('aula', $firstRow)) {
            return response()->json([
                'message' => 'El archivo debe contener la columna «Aula» con el código del grupo de cada estudiante '
                    . '(por ejemplo 11B2026). Descargá la plantilla actualizada.',
            ], 422);
        }

        // Aulas de la institución indexadas por código en mayúsculas: una sola
        // consulta en lugar de una por fila.
        $aulasPorCodigo = Group::query()
            ->whereNotNull('group_code')
            ->get()
            ->keyBy(fn ($g) => Str::upper(trim($g->group_code)));

        if ($aulasPorCodigo->isEmpty()) {
            return response()->json([
                'message' => 'No hay ningún grupo con código definido en esta institución. '
                    . 'Creá las aulas primero (POST /api/groups) antes de cargar estudiantes.',
            ], 422);
        }

        $created         = 0;
        $updated         = 0;
        $usersCreated    = 0;
        $matriculados    = 0; // altas de matrícula (estudiante nuevo en su aula)
        $reasignados     = 0; // cambios de aula sobre estudiantes que ya existían
        $aulasTocadas    = []; // group_id → recuento de student_count al final
        $newUsers        = []; // usuarios creados → reciben enlace de contraseña tras el commit
        $errors          = [];
        $acciones        = []; // fila → 'crear' | 'actualizar' (para la vista previa)
        $correosVistos   = []; // correo → primera fila donde aparece
        $validAdeValues  = array_map(fn($c) => $c->value, AdecuacionType::cases());
        $validStatValues = array_map(fn($c) => $c->value, StudentStatus::cases());

        // Todo lo creado pertenece a la institución del usuario autenticado.
        $institutionId = $request->user()->institution_id;

        // Hash de una contraseña aleatoria que nadie conoce: deja las cuentas nuevas
        // inservibles hasta que su dueño fije la suya con el enlace del correo.
        // Se calcula UNA vez por carga y no por fila: con BCRYPT_ROUNDS=12 cada hash
        // cuesta ~180 ms, y 300 filas tardaban casi un minuto —el navegador cortaba
        // la petición antes de terminar—. Compartirlo no debilita nada: el secreto
        // se descarta aquí mismo y no hay forma de entrar con él.
        $hashInservible = Hash::make(Str::random(40));

        try {
            DB::transaction(function () use (
                $rows, $validAdeValues, $validStatValues, $institutionId, $aulasPorCodigo,
                &$created, &$updated, &$errors, &$usersCreated, &$newUsers,
                &$matriculados, &$reasignados, &$aulasTocadas, $enrollment, &$acciones, $simular, &$correosVistos, $hashInservible
            ) {
                foreach ($rows as $idx => $row) {
                    $lineNumber = $idx; // los parsers indexan por fila del archivo

                    $row = Arr::map($row, fn($v) => is_string($v) ? trim($v) : $v);

                    // --- adecuacion_type ---
                    if (!empty($row['adecuacion_type'])) {
                        $val = Str::lower(Str::ascii($row['adecuacion_type'])); // «evaluación» = «evaluacion»
                        if (!in_array($val, $validAdeValues, true)) {
                            $errors[] = "Fila {$lineNumber}: «Tipo de adecuación» inválido «{$row['adecuacion_type']}». Valores aceptados: " . implode(', ', $validAdeValues) . '.';
                            continue;
                        }
                        $row['adecuacion_type'] = $val;
                    } else {
                        $row['adecuacion_type'] = null;
                    }

                    // --- status (activo/inactivo/suspendido, o en inglés) ---
                    if (!empty($row['status'])) {
                        $estado = BulkTemplateService::statusValue((string) $row['status']);
                        if (!in_array($estado, $validStatValues, true)) {
                            $errors[] = "Fila {$lineNumber}: «Estado» inválido «{$row['status']}». Valores aceptados: " . implode(', ', array_keys(BulkTemplateService::STATUS_LABELS)) . '.';
                            continue;
                        }
                        $row['status'] = $estado;
                    }

                    // --- aula (obligatoria) ---
                    //
                    // El grupo tiene que existir ya: la carga masiva no crea aulas.
                    // Un typo en el código crearía un grupo fantasma con un alumno
                    // dentro, invisible para el docente que sí tiene asignada la
                    // buena. Las aulas las crea el admin con POST /api/groups.
                    $codigoAula = Str::upper(trim((string) ($row['aula'] ?? '')));

                    if ($codigoAula === '') {
                        $errors[] = "Fila {$lineNumber}: «Aula» es obligatoria. Indicá el código del grupo (por ejemplo 11B2026).";
                        continue;
                    }

                    $aula = $aulasPorCodigo->get($codigoAula);

                    if (!$aula) {
                        $disponibles = $aulasPorCodigo->keys()->take(8)->implode(', ');
                        $errors[] = "Fila {$lineNumber}: el aula «{$row['aula']}» no existe en tu institución. Aulas disponibles: {$disponibles}.";
                        continue;
                    }

                    // --- parent_email ---
                    if (!empty($row['parent_email']) && !filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Fila {$lineNumber}: «Correo del tutor» inválido «{$row['parent_email']}».";
                        continue;
                    }

                    // --- birth_date ---
                    if (!empty($row['birth_date'])) {
                        $d = \DateTime::createFromFormat('Y-m-d', $row['birth_date']);
                        if (!$d || $d->format('Y-m-d') !== $row['birth_date']) {
                            $errors[] = "Fila {$lineNumber}: «Fecha de nacimiento» inválida «{$row['birth_date']}». Formato esperado: AAAA-MM-DD.";
                            continue;
                        }
                    }

                    // --- email (si viene) ---
                    $email = !empty($row['email']) ? Str::lower($row['email']) : null;
                    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Fila {$lineNumber}: «Correo institucional» inválido «{$row['email']}».";
                        continue;
                    }

                    // El mismo correo dos veces en el archivo es un error de quien
                    // lo armó: la segunda fila encontraba la cuenta recién creada
                    // por la primera y la «actualizaba», cambiándola de aula sin aviso.
                    if ($email !== null && isset($correosVistos[$email])) {
                        $errors[] = "Fila {$lineNumber}: el correo «{$email}» está repetido en el archivo (fila {$correosVistos[$email]}).";
                        continue;
                    }
                    if ($email !== null) {
                        $correosVistos[$email] = $lineNumber;
                    }

                    // --- Resolver el USUARIO dueño del perfil (siempre dentro del tenant) ---
                    $user = null;
                    if (!empty($row['user_id'])) {
                        $user = User::where('institution_id', $institutionId)
                            ->where('id', $row['user_id'])
                            ->first();
                        if (!$user) {
                            $errors[] = "Fila {$lineNumber}: el «ID de usuario» «{$row['user_id']}» no existe en tu institución.";
                            continue;
                        }
                    } elseif ($email) {
                        $user = User::where('institution_id', $institutionId)
                            ->where('email', $email)
                            ->first();
                    }

                    // --- Buscar estudiante existente (Student ya está scoped por tenant) ---
                    $student = null;
                    if ($user) {
                        $student = Student::where('user_id', $user->id)->first();
                    }
                    if (!$student && !empty($row['student_code'])) {
                        $student = Student::where('student_code', $row['student_code'])->first();
                        if ($student && !$user) {
                            $user = User::where('institution_id', $institutionId)
                                ->where('id', $student->user_id)
                                ->first();
                        }
                    }

                    // --- student_code único ---
                    //
                    // Se comprueba ANTES de crear la cuenta: si se hiciera después,
                    // una fila rechazada por código duplicado dejaría un usuario
                    // huérfano —sin perfil de estudiante, capaz de autenticarse y
                    // con el email ya consumido—.
                    //
                    // Acotado al tenant, que es lo que exige la constraint
                    // `students_institucion_codigo_unique (institution_id,
                    // student_code)`. Hasta el 08/08/2026 la constraint era global y
                    // esta comprobación no: un código ya usado por otro centro
                    // pasaba el filtro y reventaba contra la base, y como en
                    // PostgreSQL una violación aborta la transacción entera se
                    // perdía el archivo completo. Si se vuelven a separar, el
                    // síntoma es ese — comprobación y constraint tienen que hablar
                    // del mismo alcance.
                    if (!empty($row['student_code'])) {
                        $duplicateQuery = Student::where('student_code', $row['student_code']);
                        if ($student) {
                            $duplicateQuery->where('user_id', '!=', $student->user_id);
                        }
                        if ($duplicateQuery->exists()) {
                            $errors[] = "Fila {$lineNumber}: el «Código de estudiante» «{$row['student_code']}» ya está en uso.";
                            continue;
                        }
                    }

                    // --- Si no hay usuario ni estudiante: crear cuenta nueva (requiere email + full_name) ---
                    if (!$user && !$student) {
                        if (!$email) {
                            $errors[] = "Fila {$lineNumber}: para crear un estudiante nuevo se requiere el «Correo institucional» (o un «ID de usuario» existente).";
                            continue;
                        }
                        $fullName = trim((string) ($row['full_name'] ?? ''));
                        if ($fullName === '') {
                            $errors[] = "Fila {$lineNumber}: «Nombre completo» es obligatorio para crear el usuario.";
                            continue;
                        }
                        if (User::where('email', $email)->exists()) {
                            $errors[] = "Fila {$lineNumber}: el correo «{$email}» ya está en uso.";
                            continue;
                        }

                        $user = User::create([
                            'institution_id' => $institutionId,
                            'full_name'      => $fullName,
                            'email'          => $email,
                            // Contraseña no usable: el usuario la define vía el enlace que recibe por correo.
                            'password_hash'  => $hashInservible,
                            'user_type'      => UserType::Student->value,

                            // Nace INACTIVA: la activa su dueño al definir la
                            // contraseña desde el correo de alta. Antes se creaba
                            // ya activa, así que en el panel no había forma de
                            // distinguir a quien nunca entró de quien lleva meses
                            // usando la plataforma — y aun así no podía entrar,
                            // porque su contraseña es aleatoria.
                            'status'         => UserStatus::Inactive->value,
                        ]);

                        $usersCreated++;
                        $newUsers[] = $user;
                    }

                    // institution_id nunca se toma del archivo — lo asigna TenantScoped.
                    // El user_id del perfil siempre proviene del usuario resuelto/creado.
                    $data = Arr::only($row, self::ALLOWED_COLUMNS);
                    // Quitar celdas vacías: las columnas nullable quedan en NULL (no en '')
                    // y las que tienen default (status, exams_completed_count) lo aplican.
                    $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
                    $data['user_id'] = $user?->id ?? $student->user_id;

                    // Los campos desnormalizados de la ficha salen del aula, nunca
                    // del archivo: así no pueden contradecir la matrícula.
                    $data['grade']      = $aula->grade;
                    $data['section']    = $aula->section;
                    $data['group_code'] = $aula->group_code;

                    try {
                        if ($student) {
                            // No reasignar la PK (user_id) al actualizar
                            $student->fill(Arr::except($data, ['user_id']));
                            $student->save();
                            $updated++;
                            $acciones[$lineNumber] = 'actualizar';
                        } else {
                            $student = Student::create($data);
                            $created++;
                            $acciones[$lineNumber] = 'crear';
                        }

                        // --- Matrícula en el aula ---
                        $studentUserId = $student->user_id;

                        // Acotado al centro: sin esto, una matrícula de otra
                        // institución —que el modelo de datos no debería permitir,
                        // pero la consulta no comprobaba— haría que el recuento de
                        // más abajo escribiera en un grupo ajeno.
                        $aulaActual = DB::table('group_students')
                            ->where('institution_id', $institutionId)
                            ->where('student_user_id', $studentUserId)
                            ->whereNull('left_at')
                            ->value('group_id');

                        if ($aulaActual === $aula->id) {
                            // Ya está donde debe: nada que hacer.
                        } elseif ($aulaActual === null) {
                            $enrollment->abrirMatricula($studentUserId, $aula->id, $institutionId);
                            $aulasTocadas[$aula->id] = true;
                            $matriculados++;
                        } else {
                            // Cambio de aula. Solo puede ocurrir aquí, sobre un
                            // estudiante que ya existía: la creación abre matrícula,
                            // el traslado se hace actualizando su fila.
                            DB::table('group_students')
                                ->where('institution_id', $institutionId)
                                ->where('student_user_id', $studentUserId)
                                ->whereNull('left_at')
                                ->update(['left_at' => now()]);

                            $enrollment->abrirMatricula($studentUserId, $aula->id, $institutionId);

                            $aulasTocadas[$aula->id]   = true;
                            $aulasTocadas[$aulaActual] = true;
                            $reasignados++;
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Fila {$lineNumber}: error al guardar — " . $e->getMessage();
                    }

                    unset($row, $data);
                }

                if ($simular) {
                    BulkPreview::revertir();
                }
            });
        } catch (SimulacionRevertida) {
            return response()->json(BulkPreview::respuesta($rows, $errors, $acciones, ['full_name', 'email', 'aula']));
        }

        // Recuento de las aulas afectadas (RN-STU-012). Una sola pasada al
        // final: durante el bucle el contador cambiaría en cada fila.
        foreach (array_keys($aulasTocadas) as $groupId) {
            $enrollment->recontarAula($groupId, $institutionId);
        }

        // Encolar el enlace de "establece tu contraseña" a los usuarios creados.
        // FUERA de la transacción: el envío real lo hace el worker; la request no se bloquea.
        $emailsQueued  = 0;
        $emailFailures = [];
        // Cada enlace se prepara en cola (ver EnviarEnlaceDeAlta): hacerlo aquí
        // costaba un hash bcrypt por cuenta y la petición superaba el minuto.
        foreach ($newUsers as $newUser) {
            EnviarEnlaceDeAlta::dispatch($newUser->id);
            $emailsQueued++;
        }

        return response()->json([
            'total_rows'     => $totalRows,
            'created'        => $created,
            'updated'        => $updated,
            'users_created'  => $usersCreated,
            // Matrícula: altas nuevas y traslados. Se informan por separado
            // porque un traslado no anunciado es un cambio silencioso de a qué
            // docente pasa a ver el expediente del estudiante.
            'matriculados'   => $matriculados,
            'reasignados'    => $reasignados,
            'aulas_afectadas' => count($aulasTocadas),
            'emails_queued'  => $emailsQueued,
            'email_failures' => $emailFailures,
            'skipped'        => count($errors),
            'errors'         => $errors,
        ]);
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
