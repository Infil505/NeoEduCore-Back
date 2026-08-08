<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Students\Student;
use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
                    new OA\Property(property: 'user_type', type: 'string', enum: ['admin', 'teacher', 'student', 'parent'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado'),
            new OA\Response(response: 403, description: 'No autorizado (no admin)'),
            new OA\Response(response: 422, description: 'Validación fallida'),
        ]
    )]
    public function register(Request $request)
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
            'user_type' => ['nullable', Rule::in([
                UserType::Admin->value,
                UserType::Teacher->value,
                UserType::Student->value,
                UserType::Parent->value,
            ])],
        ]);

        $email = strtolower($data['email']);

        // El usuario se crea SIEMPRE dentro de la institución del admin autenticado.
        $institutionId = $request->user()->institution_id;

        // Rol: explícito si viene en el request; si no, se infiere por el email.
        $userType = $data['user_type'] ?? $this->detectUserTypeByEmail($email);

        // Transacción: el usuario y su perfil de estudiante se crean de forma
        // atómica. Si fallara la creación del perfil, no queda un User huérfano.
        $user = DB::transaction(function () use ($data, $email, $institutionId, $userType) {
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
                    'student_code' => 'STU-' . strtoupper(str_replace('-', '', $user->id)),

                    // Datos académicos iniciales (se completan luego)
                    'grade' => null,
                    'section' => null,

                    'status' => StudentStatus::Active->value,
                    'enrolled_at' => now(),
                    'last_activity_at' => null,
                    'exams_completed_count' => 0,
                    'overall_average' => 0,
                ]);
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

        // Nota: el rol `parent` queda RESERVADO para un futuro portal de acudientes,
        // pero NO se infiere por email: hoy no tiene rutas, así que un usuario parent
        // recibiría 403 en todo. Si se necesita, el admin puede crearlo explícitamente
        // pasando user_type=parent en /register.
        return UserType::Student->value;
    }
}
