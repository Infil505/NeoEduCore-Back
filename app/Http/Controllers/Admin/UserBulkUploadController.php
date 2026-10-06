<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Admin\User;
use App\Services\Auth\PasswordSetupService;
use App\Services\Imports\BulkFileReader;
use App\Services\Imports\BulkTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Carga masiva por rol.
 *
 * La plantilla sirve para los tres roles. La subida de estudiantes sigue en
 * `POST /api/students/bulk-upload` porque además crea la ficha y matricula en
 * un aula; aquí se suben docentes y administradores, que solo son cuentas.
 */
class UserBulkUploadController extends Controller
{
    /** Roles que se suben por este controlador (los estudiantes, no). */
    private const STAFF_ROLES = ['teacher', 'admin'];

    #[OA\Get(
        path: '/api/users/bulk-upload/template',
        summary: 'Descargar la plantilla de carga masiva de un rol (CSV con ";" o XLSX)',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', enum: ['student', 'teacher', 'admin'])),
            new OA\Parameter(name: 'format', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx'], default: 'csv')),
        ],
        responses: [new OA\Response(response: 200, description: 'Archivo de plantilla')]
    )]
    public function template(Request $request, BulkTemplateService $templates)
    {
        $data = $request->validate([
            'role'   => ['required', Rule::in(BulkTemplateService::ROLES)],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);

        return $templates->download($data['role'], $data['format'] ?? 'csv');
    }

    #[OA\Post(
        path: '/api/users/bulk-upload',
        summary: 'Carga masiva de docentes o administradores (CSV o XLSX)',
        tags: ['Users'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Resumen de la carga'),
            new OA\Response(response: 422, description: 'Archivo o rol no válido'),
        ]
    )]
    public function upload(Request $request, BulkFileReader $reader, PasswordSetupService $passwordSetup)
    {
        $maxKb = config('bulk.students.max_mb') * 1024;

        $data = $request->validate([
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', "max:{$maxKb}"],
        ]);

        [$rows, $parseError] = $reader->read($request->file('file'));

        if ($parseError) {
            return response()->json(['message' => $parseError], 422);
        }

        $totalRows = count($rows);

        if ($totalRows === 0) {
            return response()->json(['message' => 'El archivo no contiene filas de datos.'], 422);
        }

        if ($totalRows > config('bulk.students.max_rows')) {
            return response()->json([
                'message' => 'El archivo excede el límite de ' . config('bulk.students.max_rows') . " filas. Se encontraron {$totalRows} filas.",
            ], 422);
        }

        $firstRow = reset($rows);
        if (!array_key_exists('full_name', $firstRow) || !array_key_exists('email', $firstRow)) {
            return response()->json([
                'message' => 'El archivo debe contener las columnas «Nombre completo» y «Correo institucional». Descarga la plantilla actualizada.',
            ], 422);
        }

        $role          = $data['role'];
        $institutionId = $request->user()->institution_id;
        $errors        = [];
        $newUsers      = [];
        $vistos        = []; // correos ya procesados en este archivo

        DB::transaction(function () use ($rows, $role, $institutionId, &$errors, &$newUsers, &$vistos) {
            foreach ($rows as $lineNumber => $row) {
                $fullName = trim((string) ($row['full_name'] ?? ''));
                $email    = Str::lower(trim((string) ($row['email'] ?? '')));

                if ($fullName === '') {
                    $errors[] = "Fila {$lineNumber}: «Nombre completo» es obligatorio.";
                    continue;
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila {$lineNumber}: «Correo institucional» inválido «{$row['email']}».";
                    continue;
                }
                if (isset($vistos[$email])) {
                    $errors[] = "Fila {$lineNumber}: el correo «{$email}» está repetido en el archivo (fila {$vistos[$email]}).";
                    continue;
                }
                $vistos[$email] = $lineNumber;

                // `users.email` es único en toda la plataforma, no por institución.
                if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                    $errors[] = "Fila {$lineNumber}: el correo «{$email}» ya está en uso.";
                    continue;
                }

                $newUsers[] = User::create([
                    'institution_id' => $institutionId,
                    'full_name'      => $fullName,
                    'email'          => $email,
                    // Contraseña no usable: la define el usuario con el enlace del correo.
                    'password_hash'  => Hash::make(Str::random(40)),
                    'user_type'      => $role,
                    // Nace inactiva, igual que en la carga de estudiantes: se
                    // activa al definir la contraseña desde el correo de alta.
                    'status'         => UserStatus::Inactive->value,
                ]);
            }
        });

        // Encolar el enlace de "establece tu contraseña" fuera de la transacción.
        $emailsQueued  = 0;
        $emailFailures = [];
        foreach ($newUsers as $newUser) {
            if ($passwordSetup->sendSetupLink($newUser)) {
                $emailsQueued++;
            } else {
                $emailFailures[] = $newUser->email;
            }
        }

        // Mismas claves que la carga de estudiantes: el panel muestra un único resumen.
        return response()->json([
            'total_rows'     => $totalRows,
            'created'        => count($newUsers),
            'updated'        => 0,
            'users_created'  => count($newUsers),
            'emails_queued'  => $emailsQueued,
            'email_failures' => $emailFailures,
            'skipped'        => count($errors),
            'errors'         => $errors,
        ]);
    }
}
