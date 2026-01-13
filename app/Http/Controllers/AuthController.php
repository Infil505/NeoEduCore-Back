<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Registro (AUTOREGISTRO INCLUIDO)
     * - Crea usuario y (opcional) institución.
     * - Detecta rol por email (NO se acepta user_type desde request).
     * - Si el rol es student, crea automáticamente el perfil Student.
     * - Devuelve token Sanctum.
     */
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

            // SaaS: asociar a institución existente
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

            // o crear institución desde el registro
            'institution_code' => ['nullable', 'string', 'max:40'],
            'institution_name' => ['nullable', 'string', 'max:120'],
        ]);

        $email = strtolower($data['email']);

        // Crear o asociar institución
        $institutionId = $data['institution_id'] ?? null;

        if (
            !$institutionId &&
            !empty($data['institution_code']) &&
            !empty($data['institution_name'])
        ) {
            $inst = Institution::create([
                'code' => strtoupper(trim($data['institution_code'])),
                'name' => trim($data['institution_name']),
                'is_active' => true,
            ]);

            $institutionId = $inst->id;
        }

        // Rol NO viene del request; se detecta por email
        $userType = $this->detectUserTypeByEmail($email);

        $user = User::create([
            'institution_id' => $institutionId,
            'full_name' => trim($data['full_name']),
            'email' => $email,
            'password_hash' => Hash::make($data['password']),
            'user_type' => $userType,
            'status' => UserStatus::Active->value,
        ]);

        // AUTOREGISTRO: si es estudiante, crear perfil Student
        if ($userType === UserType::Student->value) {
            Student::create([
                'institution_id' => $institutionId,
                'user_id' => $user->id,

                // Código provisional (puede editarse luego desde el panel)
                'student_code' => 'STU-' . strtoupper(substr($user->id, 0, 8)),

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
        ], 201);
    }

    /**
     * Login
     */
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

    /**
     * Usuario autenticado
     */
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

    /**
     * Logout
     */
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

        if (str_contains($email, 'parent') || str_contains($email, 'padre')) {
            return UserType::Parent->value;
        }

        return UserType::Student->value;
    }
}