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
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Services\Auth\PasswordSetupService;
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

    private const BULK_MAX_ROWS    = 5000;
    private const BULK_MAX_MB      = 5;
    private const VALID_SECTIONS   = ['A', 'B', 'C', 'D'];
    private const GRADE_MIN        = 6;
    private const GRADE_MAX        = 12;

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
    private const TEMPLATE_COLUMNS = [
        'full_name', 'email', 'user_id', 'student_code', 'aula', 'status',
        'birth_date', 'parent_name', 'parent_email', 'adecuacion_type',
    ];

    public function index(Request $request)
    {
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
            'data' => $query->paginate(20),
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
            'grade'          => ['sometimes', 'integer', 'between:' . self::GRADE_MIN . ',' . self::GRADE_MAX],
            'section'        => ['sometimes', 'string', Rule::in(self::VALID_SECTIONS)],
            'birth_date'     => ['nullable', 'date'],
            'parent_name'    => ['nullable', 'string', 'max:120'],
            'parent_email'   => ['nullable', 'email', 'max:120'],
            'group_code'     => ['nullable', 'string', 'max:40'],
            'adecuacion_type'  => ['nullable', Rule::in(array_map(fn($c) => $c->value, AdecuacionType::cases()))],
            'learning_style'   => ['nullable', Rule::in(array_map(fn($c) => $c->value, LearningStyle::cases()))],
        ]);

        if (isset($data['section'])) {
            $data['section'] = strtoupper($data['section']);
        }

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
    public function bulkUpload(Request $request, PasswordSetupService $passwordSetup)
    {
        $maxKb = self::BULK_MAX_MB * 1024;

        $request->validate([
            'file' => ['required', 'file', "mimes:csv,txt,xlsx", "max:{$maxKb}"],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        [$rows, $parseError] = $this->parseFile($file, $ext);

        if ($parseError) {
            return response()->json(['message' => $parseError], 422);
        }

        $totalRows = count($rows);

        if ($totalRows === 0) {
            return response()->json(['message' => 'El archivo no contiene filas de datos.'], 422);
        }

        if ($totalRows > self::BULK_MAX_ROWS) {
            return response()->json([
                'message' => "El archivo excede el límite de " . self::BULK_MAX_ROWS . " filas. Se encontraron {$totalRows} filas.",
            ], 422);
        }

        // Verificar que exista al menos una columna identificadora
        $firstRow = $rows[0];
        if (
            !array_key_exists('user_id', $firstRow) &&
            !array_key_exists('student_code', $firstRow) &&
            !array_key_exists('email', $firstRow)
        ) {
            return response()->json([
                'message' => 'El archivo debe contener al menos una columna identificadora: "email" (para crear), "user_id" o "student_code".',
            ], 422);
        }

        // El aula es obligatoria. Sin ella, la carga dejaba estudiantes con una
        // etiqueta de sección en la ficha pero sin matrícula en ningún grupo, y
        // desde el modelo de asignaciones eso los vuelve invisibles: no los ve
        // ningún docente, no reciben exámenes y no salen en informes.
        if (!array_key_exists('aula', $firstRow)) {
            return response()->json([
                'message' => 'El archivo debe contener la columna "aula" con el código del grupo de cada estudiante '
                    . '(por ejemplo 11B2026). Descargá la plantilla actualizada desde /api/students/bulk-upload/template.',
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
        $validAdeValues  = array_map(fn($c) => $c->value, AdecuacionType::cases());
        $validStatValues = array_map(fn($c) => $c->value, StudentStatus::cases());

        // Todo lo creado pertenece a la institución del usuario autenticado.
        $institutionId = $request->user()->institution_id;

        DB::transaction(function () use (
            $rows, $validAdeValues, $validStatValues, $institutionId, $aulasPorCodigo,
            &$created, &$updated, &$errors, &$usersCreated, &$newUsers,
            &$matriculados, &$reasignados, &$aulasTocadas
        ) {
            foreach ($rows as $idx => $row) {
                $lineNumber = $idx + 2; // +2: encabezado en fila 1

                $row = Arr::map($row, fn($v) => is_string($v) ? trim($v) : $v);

                // --- adecuacion_type ---
                if (!empty($row['adecuacion_type'])) {
                    $val = Str::lower($row['adecuacion_type']);
                    if (!in_array($val, $validAdeValues, true)) {
                        $errors[] = "Fila {$lineNumber}: adecuacion_type inválido «{$row['adecuacion_type']}». Valores aceptados: " . implode(', ', $validAdeValues) . '.';
                        continue;
                    }
                    $row['adecuacion_type'] = $val;
                } else {
                    $row['adecuacion_type'] = null;
                }

                // --- status ---
                if (!empty($row['status'])) {
                    if (!in_array($row['status'], $validStatValues, true)) {
                        $errors[] = "Fila {$lineNumber}: status inválido «{$row['status']}». Valores aceptados: " . implode(', ', $validStatValues) . '.';
                        continue;
                    }
                }

                // --- aula (obligatoria) ---
                //
                // El grupo tiene que existir ya: la carga masiva no crea aulas.
                // Un typo en el código crearía un grupo fantasma con un alumno
                // dentro, invisible para el docente que sí tiene asignada la
                // buena. Las aulas las crea el admin con POST /api/groups.
                $codigoAula = Str::upper(trim((string) ($row['aula'] ?? '')));

                if ($codigoAula === '') {
                    $errors[] = "Fila {$lineNumber}: «aula» es obligatoria. Indicá el código del grupo (por ejemplo 11B2026).";
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
                    $errors[] = "Fila {$lineNumber}: parent_email inválido «{$row['parent_email']}».";
                    continue;
                }

                // --- birth_date ---
                if (!empty($row['birth_date'])) {
                    $d = \DateTime::createFromFormat('Y-m-d', $row['birth_date']);
                    if (!$d || $d->format('Y-m-d') !== $row['birth_date']) {
                        $errors[] = "Fila {$lineNumber}: birth_date inválido «{$row['birth_date']}». Formato esperado: YYYY-MM-DD.";
                        continue;
                    }
                }

                // --- email (si viene) ---
                $email = !empty($row['email']) ? Str::lower($row['email']) : null;
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila {$lineNumber}: email inválido «{$row['email']}».";
                    continue;
                }

                // --- Resolver el USUARIO dueño del perfil (siempre dentro del tenant) ---
                $user = null;
                if (!empty($row['user_id'])) {
                    $user = User::where('institution_id', $institutionId)
                        ->where('id', $row['user_id'])
                        ->first();
                    if (!$user) {
                        $errors[] = "Fila {$lineNumber}: user_id «{$row['user_id']}» no existe en tu institución.";
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
                        $errors[] = "Fila {$lineNumber}: student_code «{$row['student_code']}» ya está en uso.";
                        continue;
                    }
                }

                // --- Si no hay usuario ni estudiante: crear cuenta nueva (requiere email + full_name) ---
                if (!$user && !$student) {
                    if (!$email) {
                        $errors[] = "Fila {$lineNumber}: para crear un estudiante nuevo se requiere la columna «email» (o un «user_id» existente).";
                        continue;
                    }
                    $fullName = trim((string) ($row['full_name'] ?? ''));
                    if ($fullName === '') {
                        $errors[] = "Fila {$lineNumber}: «full_name» es obligatorio para crear el usuario.";
                        continue;
                    }
                    if (User::where('email', $email)->exists()) {
                        $errors[] = "Fila {$lineNumber}: el email «{$email}» ya está en uso.";
                        continue;
                    }

                    $user = User::create([
                        'institution_id' => $institutionId,
                        'full_name'      => $fullName,
                        'email'          => $email,
                        // Contraseña no usable: el usuario la define vía el enlace que recibe por correo.
                        'password_hash'  => Hash::make(Str::random(40)),
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
                    } else {
                        $student = Student::create($data);
                        $created++;
                    }

                    // --- Matrícula en el aula ---
                    $studentUserId = $student->user_id;

                    $aulaActual = DB::table('group_students')
                        ->where('student_user_id', $studentUserId)
                        ->whereNull('left_at')
                        ->value('group_id');

                    if ($aulaActual === $aula->id) {
                        // Ya está donde debe: nada que hacer.
                    } elseif ($aulaActual === null) {
                        $this->abrirMatricula($studentUserId, $aula->id, $institutionId);
                        $aulasTocadas[$aula->id] = true;
                        $matriculados++;
                    } else {
                        // Cambio de aula. Solo puede ocurrir aquí, sobre un
                        // estudiante que ya existía: la creación abre matrícula,
                        // el traslado se hace actualizando su fila.
                        DB::table('group_students')
                            ->where('student_user_id', $studentUserId)
                            ->whereNull('left_at')
                            ->update(['left_at' => now()]);

                        $this->abrirMatricula($studentUserId, $aula->id, $institutionId);

                        $aulasTocadas[$aula->id]   = true;
                        $aulasTocadas[$aulaActual] = true;
                        $reasignados++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Fila {$lineNumber}: error al guardar — " . $e->getMessage();
                }

                unset($row, $data);
            }
        });

        // Recuento de las aulas afectadas (RN-STU-012). Una sola pasada al
        // final: durante el bucle el contador cambiaría en cada fila.
        foreach (array_keys($aulasTocadas) as $groupId) {
            DB::table('groups')->where('id', $groupId)->update([
                'student_count' => DB::table('group_students')
                    ->where('group_id', $groupId)
                    ->whereNull('left_at')
                    ->count(),
                'updated_at' => now(),
            ]);
        }

        // Encolar el enlace de "establece tu contraseña" a los usuarios creados.
        // FUERA de la transacción: el envío real lo hace el worker; la request no se bloquea.
        $emailsQueued  = 0;
        $emailFailures = [];
        foreach ($newUsers as $newUser) {
            if ($passwordSetup->sendSetupLink($newUser)) {
                $emailsQueued++;
            } else {
                $emailFailures[] = $newUser->email;
            }
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

    /**
     * Abre (o reabre) la matrícula de un estudiante en un aula.
     *
     * `upsert` con `left_at = NULL` en conflicto: si el estudiante ya estuvo en
     * esa aula y se fue, se reactiva la fila original conservando su
     * `joined_at`, igual que hace `GroupController::addStudents()`.
     */
    private function abrirMatricula(string $studentUserId, string $groupId, string $institutionId): void
    {
        DB::table('group_students')->upsert(
            [[
                'institution_id'  => $institutionId,
                'group_id'        => $groupId,
                'student_user_id' => $studentUserId,
                'joined_at'       => now(),
                'left_at'         => null,
            ]],
            ['group_id', 'student_user_id'],
            ['left_at']
        );
    }

    #[OA\Get(
        path: '/api/students/bulk-upload/template',
        summary: 'Descargar plantilla CSV para carga masiva',
        tags: ['Students'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Archivo CSV de plantilla'),
        ]
    )]
    public function bulkUploadTemplate()
    {
        $filename = 'plantilla_estudiantes.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = self::TEMPLATE_COLUMNS;

        // Fila de instrucciones (se muestra como primera fila de datos en Excel)
        $instructions = [
            '(Nombre completo del estudiante — requerido para crear cuenta nueva)',
            '(Correo del estudiante — requerido para crear; recibe enlace para fijar contraseña)',
            '(UUID de usuario existente — opcional; si se indica NO se crea cuenta)',
            '(Código único, ej: EST-0001 — opcional)',
            '(OBLIGATORIO — código del aula ya creada, ej: 11B2026. El grado y la seccion se toman de ella)',
            '(active, inactive, suspended — default: active)',
            '(AAAA-MM-DD, ej: 2008-03-15)',
            '(Nombre completo del tutor)',
            '(Email del tutor)',
            '(acceso, contenido, evaluacion — o dejar vacío)',
        ];

        $examples = [
            // Crear cuenta nueva (con email, sin user_id) y matricularla en su aula
            [
                'María García Solano',
                'maria.garcia@ejemplo.com',
                '',
                'EST-0001',
                '10A2026',
                'active',
                '2008-03-15',
                'Lucía Solano',
                'lucia.solano@ejemplo.com',
                '',
            ],
            [
                'Carlos López Mora',
                'carlos.lopez@ejemplo.com',
                '',
                'EST-0002',
                '11B2026',
                'active',
                '2007-07-22',
                'Pedro López',
                'pedro.lopez@ejemplo.com',
                'acceso',
            ],
            // Estudiante que YA existe: esta fila lo ACTUALIZA. Si el aula es
            // distinta de la actual, se le da de baja en la anterior y de alta
            // en esta. Es la única vía por la que un alumno cambia de aula.
            [
                '',
                '',
                'yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy',
                'EST-0003',
                '11B2026',
                'inactive',
                '2009-11-01',
                'Ana Jiménez Castro',
                'ana.jimenez@ejemplo.com',
                'contenido',
            ],
        ];

        return response()->streamDownload(function () use ($columns, $instructions, $examples) {
            $output = fopen('php://output', 'w');

            // BOM UTF-8 para compatibilidad con Excel en Windows
            fputs($output, "\xEF\xBB\xBF");

            fputcsv($output, $columns);
            fputcsv($output, $instructions);
            foreach ($examples as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, $headers);
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

    // -------------------------------------------------------------------------

    /**
     * Lee un archivo CSV o XLSX y devuelve [rows[], errorMessage|null].
     */
    private function parseFile(\Illuminate\Http\UploadedFile $file, string $ext): array
    {
        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->parseCsv($file);
        }

        if ($ext === 'xlsx') {
            return $this->parseXlsx($file);
        }

        return [[], 'Formato no soportado. Use .csv o .xlsx'];
    }

    private function parseCsv(\Illuminate\Http\UploadedFile $file): array
    {
        $path   = $file->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [[], 'No se pudo leer el archivo CSV.'];
        }

        // Detectar y descartar BOM UTF-8
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = null;
        $rows   = [];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if (!$header) {
                $header = array_map(
                    fn($h) => Str::of($h)->trim()->lower()->replace(' ', '_')->toString(),
                    $data
                );
                continue;
            }

            if (count($data) !== count($header)) {
                continue; // fila mal formada — la saltamos silenciosamente
            }

            $rows[] = array_combine($header, $data);
        }

        fclose($handle);

        return [$rows, null];
    }

    private function parseXlsx(\Illuminate\Http\UploadedFile $file): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $sheet       = $spreadsheet->getActiveSheet();
            $array       = $sheet->toArray(null, true, true, false);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $header = null;
            $rows   = [];

            foreach ($array as $i => $row) {
                if ($i === 0) {
                    $header = array_map(
                        fn($h) => Str::of((string) $h)->trim()->lower()->replace(' ', '_')->toString(),
                        $row
                    );
                    continue;
                }

                // Saltar filas completamente vacías
                if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                if (count($row) !== count($header)) {
                    continue;
                }

                $rows[] = array_combine($header, $row);
            }

            return [$rows, null];
        } catch (\Exception $e) {
            return [[], 'No se pudo leer el archivo XLSX: ' . $e->getMessage()];
        }
    }
}
