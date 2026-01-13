<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Listar usuarios del tenant (filtrable)
     * Filtros: user_type, status, q (por nombre/email)
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'user_type' => ['nullable', Rule::in(['admin', 'teacher', 'student', 'parent'])],
            'status'    => ['nullable', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
            'q'         => ['nullable', 'string', 'max:120'],
        ]);

        $query = User::query()->orderByDesc('created_at');

        if (!empty($data['user_type'])) {
            $query->where('user_type', $data['user_type']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(function ($w) use ($q) {
                $w->where('full_name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%");
            });
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Ver usuario
     */
    public function show(User $user)
    {
        return response()->json([
            'data' => $user->load(['institution', 'studentProfile']),
        ]);
    }

    /**
     * Actualizar datos básicos (NO user_type)
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email'     => ['sometimes', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'status'    => ['sometimes', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
        ]);

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['full_name'])) {
            $data['full_name'] = trim($data['full_name']);
        }

        $user->fill($data);
        $user->save();

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Cambiar status (atajo)
     */
    public function setStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
        ]);

        $user->status = $data['status'];
        $user->save();

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Reset password (admin/teacher)
     * - Cambia el hash directamente
     * - Recomendado: invalidar tokens (opcional)
     */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => [
                'required',
                Password::min(8)->mixedCase()->numbers(),
                'confirmed',
            ],
        ]);

        $user->password_hash = Hash::make($data['password']);
        $user->save();

        // Opcional: revocar tokens activos
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Contraseña actualizada y tokens revocados',
        ]);
    }
}