<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Enums\StudentStatus;
use App\Enums\AdecuacionType;
use App\Enums\LearningStyle;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class StudentController extends Controller
{
    private const BULK_MAX_ROWS    = 5000;
    private const BULK_MAX_MB      = 5;
    private const VALID_SECTIONS   = ['A', 'B', 'C', 'D'];
    private const GRADE_MIN        = 6;
    private const GRADE_MAX        = 12;

    // Columnas que se leen del archivo; institution_id se ignora por seguridad (viene del tenant)
    private const ALLOWED_COLUMNS = [
        'user_id', 'student_code', 'grade', 'section', 'status',
        'birth_date', 'parent_name', 'parent_email', 'group_code', 'adecuacion_type',
    ];

    public function index(Request $request)
    {
        $query = Student::query()
            ->with('user')
            ->orderBy('student_code');

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

    public function show(string $student_user_id)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        return response()->json([
            'data' => $student,
        ]);
    }

    public function update(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        $data = $request->validate([
            'student_code'   => ['sometimes', 'string', 'max:40'],
            'grade'          => ['sometimes', 'integer', 'between:6,12'],
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
    public function bulkUpload(Request $request)
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
        if (!array_key_exists('user_id', $firstRow) && !array_key_exists('student_code', $firstRow)) {
            return response()->json([
                'message' => 'El archivo debe contener al menos la columna "user_id" o "student_code".',
            ], 422);
        }

        $created         = 0;
        $updated         = 0;
        $errors          = [];
        $validAdeValues  = array_map(fn($c) => $c->value, AdecuacionType::cases());
        $validStatValues = array_map(fn($c) => $c->value, StudentStatus::cases());

        DB::transaction(function () use (
            $rows, $validAdeValues, $validStatValues,
            &$created, &$updated, &$errors
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

                // --- section ---
                if (!empty($row['section'])) {
                    $row['section'] = strtoupper($row['section']);
                    if (!in_array($row['section'], self::VALID_SECTIONS, true)) {
                        $errors[] = "Fila {$lineNumber}: section inválida «{$row['section']}». Valores aceptados: " . implode(', ', self::VALID_SECTIONS) . '.';
                        continue;
                    }
                }

                // --- grade ---
                if (!empty($row['grade']) || $row['grade'] === '0') {
                    $grade = (int) $row['grade'];
                    if ($grade < self::GRADE_MIN || $grade > self::GRADE_MAX) {
                        $errors[] = "Fila {$lineNumber}: grade inválido «{$row['grade']}». Debe ser un número entre " . self::GRADE_MIN . " y " . self::GRADE_MAX . '.';
                        continue;
                    }
                    $row['grade'] = $grade;
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

                // --- user_id existe ---
                if (!empty($row['user_id'])) {
                    if (!User::where('id', $row['user_id'])->exists()) {
                        $errors[] = "Fila {$lineNumber}: user_id «{$row['user_id']}» no existe en el sistema.";
                        continue;
                    }
                }

                // --- Buscar estudiante existente ---
                $student = null;
                if (!empty($row['user_id'])) {
                    $student = Student::where('user_id', $row['user_id'])->first();
                }
                if (!$student && !empty($row['student_code'])) {
                    $student = Student::where('student_code', $row['student_code'])->first();
                }

                if (!$student && empty($row['user_id'])) {
                    $errors[] = "Fila {$lineNumber}: no se encontró un estudiante con ese student_code y no se indicó user_id para crear uno nuevo.";
                    continue;
                }

                // --- student_code único ---
                if (!empty($row['student_code'])) {
                    $duplicateQuery = Student::where('student_code', $row['student_code']);
                    if ($student) {
                        $duplicateQuery->where('user_id', '!=', $student->user_id);
                    }
                    if ($duplicateQuery->exists()) {
                        $errors[] = "Fila {$lineNumber}: student_code «{$row['student_code']}» ya está en uso por otro estudiante.";
                        continue;
                    }
                }

                // institution_id nunca se toma del archivo — lo asigna TenantScoped
                $data = Arr::only($row, self::ALLOWED_COLUMNS);

                try {
                    if ($student) {
                        $student->fill($data);
                        $student->save();
                        $updated++;
                    } else {
                        Student::create($data);
                        $created++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Fila {$lineNumber}: error al guardar — " . $e->getMessage();
                }

                unset($row, $data);
            }
        });

        return response()->json([
            'total_rows' => $totalRows,
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => count($errors),
            'errors'     => $errors,
        ]);
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

        $columns = self::ALLOWED_COLUMNS;

        // Fila de instrucciones (se muestra como primera fila de datos en Excel)
        $instructions = [
            '(UUID del usuario — requerido para crear)',
            '(Código único, ej: EST-0001 — requerido si no hay user_id)',
            '(Número entero 6-12)',
            '(A, B, C o D)',
            '(active, inactive, suspended — default: active)',
            '(AAAA-MM-DD, ej: 2008-03-15)',
            '(Nombre completo del tutor)',
            '(Email del tutor)',
            '(Código del grupo, ej: GRP-2024-10A)',
            '(acceso, contenido, evaluacion — o dejar vacío)',
        ];

        $examples = [
            [
                'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                'EST-0001',
                '10',
                'A',
                'active',
                '2008-03-15',
                'María García Solano',
                'maria.garcia@ejemplo.com',
                'GRP-2024-10A',
                '',
            ],
            [
                'yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy',
                'EST-0002',
                '11',
                'B',
                'active',
                '2007-07-22',
                'Carlos López Mora',
                'carlos.lopez@ejemplo.com',
                '',
                'acceso',
            ],
            [
                '',
                'EST-0003',
                '9',
                'C',
                'inactive',
                '2009-11-01',
                'Ana Jiménez Castro',
                'ana.jimenez@ejemplo.com',
                'GRP-2024-9C',
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

        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        return response()->json([
            'data' => $student,
        ]);
    }

    public function availableExams(Request $request)
    {
        $user    = $request->user();
        $student = Student::with('groups')->where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json(['data' => []]);
        }

        $groupIds = $student->groups->pluck('id');

        if ($groupIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // withCount mueve el filtro de intentos a la BD: elimina la query separada
        // y el filtrado en memoria sobre colecciones potencialmente grandes.
        $exams = Exam::query()
            ->where('status', 'active')
            ->where(fn($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->whereHas('groups', fn($q) => $q->whereIn('groups.id', $groupIds))
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
