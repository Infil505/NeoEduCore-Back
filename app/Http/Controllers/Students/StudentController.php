<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Enums\StudentStatus;
use App\Enums\AdecuacionType;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    /**
     * Listar estudiantes (admin/teacher)
     */
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

    /**
     * Ver estudiante por user_id (uuid)
     */
    public function show(string $student_user_id)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        return response()->json([
            'data' => $student,
        ]);
    }

    /**
     * Actualizar datos del perfil del estudiante
     * (datos extra: birth_date, parent, group_code, etc.)
     */
    public function update(Request $request, string $student_user_id)
    {
        $student = Student::where('user_id', $student_user_id)->firstOrFail();

        $data = $request->validate([
            'student_code' => ['sometimes', 'string', 'max:40'],
            'grade' => ['sometimes', 'integer', 'between:6,12'],
            'section' => ['sometimes', 'string', Rule::in(['A', 'B', 'C', 'D'])],

            'birth_date' => ['nullable', 'date'],
            'parent_name' => ['nullable', 'string', 'max:120'],
            'parent_email' => ['nullable', 'email', 'max:120'],

            'group_code' => ['nullable', 'string', 'max:40'],
            'adecuacion_type' => ['nullable', Rule::in(array_map(fn($c) => $c->value, AdecuacionType::cases()))],
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

    /**
     * Carga masiva de estudiantes por archivo CSV o XLSX.
     * El archivo debe contener cabeceras que correspondan a los campos
     * aceptados por el modelo (por ejemplo: user_id, student_code, grade,
     * section, birth_date, parent_name, parent_email, group_code, adecuacion_type).
     */
    public function bulkUpload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        $rows = [];

        if (in_array($ext, ['csv', 'txt'])) {
            $path = $file->getRealPath();
            if (($handle = fopen($path, 'r')) === false) {
                return response()->json(['message' => 'No se pudo leer el archivo CSV'], 422);
            }

            $header = null;
            $line = 0;
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $line++;
                if (! $header) {
                    $header = array_map(fn($h) => Str::of($h)->trim()->lower()->replace(' ', '_')->toString(), $data);
                    continue;
                }

                $rows[] = array_combine($header, $data);
            }
            fclose($handle);
        } elseif ($ext === 'xlsx') {
            if (! class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                return response()->json([
                    'message' => 'Procesamiento de .xlsx requiere la librería phpoffice/phpspreadsheet. Instálala con: composer require phpoffice/phpspreadsheet',
                ], 422);
            }

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $array = $sheet->toArray();
            $header = null;
            foreach ($array as $i => $row) {
                if ($i === 0) {
                    $header = array_map(fn($h) => Str::of($h)->trim()->lower()->replace(' ', '_')->toString(), $row);
                    continue;
                }
                $rows[] = array_combine($header, $row);
            }
        } else {
            return response()->json(['message' => 'Formato no soportado. Use .csv o .xlsx'], 422);
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        $validAdeValues = array_map(fn($c) => $c->value, AdecuacionType::cases());

        foreach ($rows as $idx => $row) {
            $lineNumber = $idx + 2; // header

            // Normalize keys to strings and trim values
            $row = Arr::map($row, fn($v) => is_string($v) ? trim($v) : $v);

            // Map adecuacion_type to allowed values (lowercase)
            if (isset($row['adecuacion_type']) && $row['adecuacion_type'] !== '') {
                $val = Str::of($row['adecuacion_type'])->trim()->lower()->toString();
                if (! in_array($val, $validAdeValues, true)) {
                    $errors[] = "Fila {$lineNumber}: valor inválido para adecuacion_type: {$row['adecuacion_type']}";
                    continue;
                }
                $row['adecuacion_type'] = $val;
            } else {
                $row['adecuacion_type'] = null;
            }

            // Normalize section
            if (isset($row['section'])) {
                $row['section'] = strtoupper($row['section']);
            }

            // Find existing student by user_id or student_code
            $student = null;
            if (! empty($row['user_id'])) {
                $student = Student::where('user_id', $row['user_id'])->first();
            }
            if (! $student && ! empty($row['student_code'])) {
                $student = Student::where('student_code', $row['student_code'])->first();
            }

            // If not found, require user_id to create (PK)
            if (! $student && empty($row['user_id'])) {
                $errors[] = "Fila {$lineNumber}: no existe user_id ni estudiante con student_code proporcionado; no se puede crear registro";
                continue;
            }

            $allowed = [
                'institution_id','user_id','student_code','grade','section','status','enrolled_at','last_activity_at','exams_completed_count','overall_average','birth_date','parent_name','parent_email','group_code','adecuacion_type'
            ];

            $data = Arr::only($row, $allowed);

            // Validate status value if provided
            if (isset($data['status']) && ! in_array($data['status'], array_map(fn($c) => $c->value, StudentStatus::cases()), true)) {
                $errors[] = "Fila {$lineNumber}: status inválido: {$data['status']}";
                continue;
            }

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
                $errors[] = "Fila {$lineNumber}: error al guardar: " . $e->getMessage();
            }
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    /**
     * Cambiar status del estudiante (admin/teacher)
     */
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

    /**
     * Perfil del estudiante autenticado (mi perfil)
     */
    public function me(Request $request)
    {
        $user = $request->user();

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
}
