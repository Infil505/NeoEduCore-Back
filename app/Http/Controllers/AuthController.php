<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Registro
     * - Crea usuario y (opcional) institución.
     * - Devuelve token Sanctum.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            // en tu tabla es full_name (no name)
            'full_name' => ['required', 'string', 'min:2', 'max:120'],

            'email' => ['required', 'email', 'max:120', 'unique:users,email'],

            // en tu tabla es password_hash (no password)
            'password' => ['required', Password::min(8)],

            // en tu esquema es user_type (no role)
            'user_type' => ['sometimes', 'in:teacher,student,admin'],

            // SaaS: permitir asociar a una institución existente
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

            // o crear institución desde el registro (opcional)
            'institution_code' => ['nullable', 'string', 'max:40'],
            'institution_name' => ['nullable', 'string', 'max:120'],
        ]);

        // Si viene institution_id, se usa.
        // Si no viene pero trae code+name, se crea.
        $institutionId = $data['institution_id'] ?? null;

        if (!$institutionId && !empty($data['institution_code']) && !empty($data['institution_name'])) {
            $inst = Institution::create([
                'code' => $data['institution_code'],
                'name' => $data['institution_name'],
            ]);
            $institutionId = $inst->id;
        }

        $user = User::create([
            'institution_id' => $institutionId,
            'full_name' => $data['full_name'],
            'email' => strtolower($data['email']),
            'password_hash' => Hash::make($data['password']),
            'user_type' => $data['user_type'] ?? UserType::Teacher->value,
            'status' => UserStatus::Active->value,
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'user_type' => $user->user_type->value,   // enum cast
                'status' => $user->status->value,         // enum cast
                'institution_id' => $user->institution_id,
            ],
            'token' => $token
        ], 201);
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($credentials['email']))->first();

        // ojo: password_hash (no password)
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
            'token' => $token
        ]);
    }

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
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }
}