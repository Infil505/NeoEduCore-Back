<?php

namespace App\Http\Controllers;

use App\Enums\AdecuacionType;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Academic\Group;
use App\Models\Students\Student;
use App\Services\Students\EnrollmentService;
use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/register',
        summary: 'Alta de usuario (solo admin)',
        description: 'Crea un usuario dentro de la institución del admin autenticado. '
            . 'El rol se toma de user_type o se infiere por el email. No devuelve token.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'full_name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                    new OA\Property(property: 'user_type', type: 'string', enum: ['admin', 'teacher', 'student'], nullable: true),
                    new OA\Property(property: 'group_id', type: 'string', format: 'uuid', nullable: true, description: 'Aula (obligatoria si el usuario es estudiante)'),
                    new OA\Property(property: 'student_code', type: 'string', nullable: true),
                    new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'parent_name', type: 'string', nullable: true),
                    new OA\Property(property: 'parent_email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'adecuacion_type', type: 'string', enum: ['acceso', 'contenido', 'evaluacion'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado'),
            new OA\Response(response: 403, description: 'No autorizado (no admin)'),
            new OA\Response(response: 422, description: 'Validación fallida'),
        ]
    )]
    public function register(Request $request, EnrollmentService $enrollment)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],

            // 8+ chars, 1 mayúscula, 1 minúscula, 1 número + confirmación
            'password' => [
                'required',
                Password::min(8)->mixedCase()->numbers(),
                'confirmed',
            ],

            // El admin puede fijar el rol explícitamente; si no, se infiere por email.
            //
            // `superadmin` NO está en la lista y no puede estarlo: esta ruta crea
            // usuarios dentro de la institución del admin autenticado, y el
            // superadmin es externo a toda institución. Permitirlo dejaría que
            // cualquier admin de centro se fabricase un operador de plataforma.
            'user_type' => ['nullable', Rule::in(UserType::rolesDeInstitucion())],

            // Ficha del estudiante. Solo se usan si el rol final es «student»;
            // el aula es obligatoria en ese caso (se comprueba más abajo, cuando
            // el rol ya está resuelto, porque puede inferirse por el email).
            'group_id'        => ['nullable', 'uuid'],
            'student_code'    => [
                'nullable', 'string', 'max:40',
                Rule::unique('students', 'student_code')->where('institution_id', $request->user()->institution_id),
            ],
            'birth_date'      => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'parent_name'     => ['nullable', 'string', 'max:120'],
            'parent_email'    => ['nullable', 'email', 'max:120'],
            'adecuacion_type' => ['nullable', Rule::in(array_map(fn ($c) => $c->value, AdecuacionType::cases()))],
        ], [
            'student_code.unique' => 'Ese código de estudiante ya está en uso en tu institución.',
        ]);

        $email = strtolower($data['email']);

        // El usuario se crea SIEMPRE dentro de la institución del admin autenticado.
        $institutionId = $request->user()->institution_id;

        // Rol: explícito si viene en el request; si no, se infiere por el email.
        $userType = $data['user_type'] ?? $this->detectUserTypeByEmail($email);

        // Un estudiante sin aula queda invisible para todo docente y no recibe
        // exámenes (ver BulkUploadStudentsTest): el alta individual exige el
        // aula igual que la carga masiva. Group es TenantScoped, así que un
        // aula de otra institución no se encuentra.
        $aula = null;
        if ($userType === UserType::Student->value) {
            $aula = empty($data['group_id']) ? null : Group::query()->whereKey($data['group_id'])->first();
            if (!$aula) {
                throw ValidationException::withMessages(['group_id' => 'Elige el aula del estudiante.']);
            }
        }

        // Transacción: el usuario y su perfil de estudiante se crean de forma
        // atómica. Si fallara la creación del perfil, no queda un User huérfano.
        $user = DB::transaction(function () use ($data, $email, $institutionId, $userType, $aula, $enrollment) {
            $user = User::create([
                'institution_id' => $institutionId,
                'full_name' => trim($data['full_name']),
                'email' => $email,
                'password_hash' => Hash::make($data['password']),
                'user_type' => $userType,

                // Activa desde el alta, a diferencia de la carga masiva.
                //
                // Aquí el administrador escribe la contraseña y se la entrega en
                // mano al usuario: no se envía ningún correo de activación, así
                // que crearla inactiva la dejaría inservible para siempre — no
                // habría enlace con el que activarla. La regla real es «una
                // cuenta está inactiva mientras nadie haya definido una
                // contraseña usable», y aquí ya la hay.
                'status' => UserStatus::Active->value,
            ]);

            // Si es estudiante, crear su perfil Student
            if ($userType === UserType::Student->value) {
                Student::create([
                    'institution_id' => $institutionId,
                    'user_id' => $user->id,

                    // Código provisional (puede editarse luego desde el panel).
                    // Se usa el UUID completo (sin guiones) para garantizar unicidad:
                    // los primeros chars de un UUIDv7 son el prefijo de timestamp y
                    // colisionan entre registros creados en la misma ventana de tiempo.
                    'student_code' => $data['student_code'] ?? ('STU-' . strtoupper(str_replace('-', '', $user->id))),

                    // Los campos desnormalizados salen del aula, igual que en
                    // la carga masiva: así no pueden contradecir la matrícula.
                    'grade'      => $aula->grade,
                    'section'    => $aula->section,
                    'group_code' => $aula->group_code,

                    'birth_date'      => $data['birth_date'] ?? null,
                    'parent_name'     => $data['parent_name'] ?? null,
                    'parent_email'    => $data['parent_email'] ?? null,
                    'adecuacion_type' => $data['adecuacion_type'] ?? null,

                    'status' => StudentStatus::Active->value,
                    'enrolled_at' => now(),
                    'last_activity_at' => null,
                    'exams_completed_count' => 0,
                    'overall_average' => 0,
                ]);

                $enrollment->abrirMatricula($user->id, $aula->id, $institutionId);
                $enrollment->recontarAula($aula->id, $institutionId);
            }

            return $user;
        });

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'user_type' => $user->user_type->value,
                'status' => $user->status->value,
                'institution_id' => $user->institution_id,
            ],
        ], 201);
    }

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Iniciar sesión',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token Sanctum'),
            new OA\Response(response: 401, description: 'Credenciales inválidas'),
            new OA\Response(response: 403, description: 'Usuario inactivo'),
        ]
    )]
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = strtolower($credentials['email']);

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($credentials['password'], $user->password_hash)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Usuario inactivo o suspendido'], 403);
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'user_type' => $user->user_type->value,
                'status' => $user->status->value,
                'institution_id' => $user->institution_id,
            ],
            'token' => $token,
        ]);
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Usuario autenticado',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos del usuario autenticado'),
            new OA\Response(response: 401, description: 'No autenticado'),
        ]
    )]
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'user_type' => $user->user_type->value,
                'status' => $user->status->value,
                'institution_id' => $user->institution_id,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Cerrar sesión',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada'),
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }

    /**
     * Detección de rol por patrón en email
     */
    private function detectUserTypeByEmail(string $email): string
    {
        $email = strtolower($email);

        if (str_contains($email, 'admin')) {
            return UserType::Admin->value;
        }

        if (str_contains($email, 'teacher') || str_contains($email, 'profesor')) {
            return UserType::Teacher->value;
        }

        // El rol `parent` se retiró el 08/08/2026: llevaba desde el diseño
        // original sin rutas, sin controladores y sin ninguna fila real.
        return UserType::Student->value;
    }
}
