<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    /**
     * Enviar enlace de recuperación de contraseña
     */
    public function sendResetLink(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email']
        ]);

        $email = strtolower($data['email']);

        try {
            // Buscar al usuario
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                // Por seguridad, devolver éxito incluso si no existe
                return response()->json([
                    'message' => 'Si el correo está registrado, recibirás un enlace de recuperación'
                ]);
            }

            // Generar token único y seguro
            $token = Str::random(64);
            
            // Limpiar tokens anteriores para este email
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();

            // Crear nuevo registro de reset
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => now()
            ]);

            // Enviar correo
            Mail::to($email)->send(new PasswordResetMail($token, $user));

            return response()->json([
                'message' => 'Enlace de recuperación enviado correctamente'
            ]);

        } catch (\Exception $e) {
            
            return response()->json([
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Verificar si un token de reset es válido
     */
    public function verifyToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email']
        ]);

        $email = strtolower($data['email']);

        try {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json([
                    'message' => 'Token de reset inválido'
                ], 400);
            }

            // Verificar el token
            if (!Hash::check($data['token'], $passwordReset->token)) {
                return response()->json([
                    'message' => 'Token de reset inválido'
                ], 400);
            }

            // Verificar expiración (24 horas)
            if (now()->diffInHours($passwordReset->created_at) > 24) {
                // Eliminar token expirado
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->delete();

                return response()->json([
                    'message' => 'El token de reset ha expirado'
                ], 400);
            }

            return response()->json([
                'message' => 'Token válido'
            ]);

        } catch (\Exception $e) {
            
            return response()->json([
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Resetear la contraseña
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)]
        ]);

        $email = strtolower($data['email']);

        try {
            // Buscar el token en la base de datos
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json([
                    'message' => 'Token de reset inválido'
                ], 400);
            }

            // Verificar el token
            if (!Hash::check($data['token'], $passwordReset->token)) {
                return response()->json([
                    'message' => 'Token de reset inválido'
                ], 400);
            }

            // Verificar expiración (24 horas)
            if (now()->diffInHours($passwordReset->created_at) > 24) {
                // Eliminar token expirado
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->delete();

                return response()->json([
                    'message' => 'El token de reset ha expirado'
                ], 400);
            }

            // Buscar al usuario y actualizar contraseña
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Actualizar la contraseña
            $user->update([
                'password' => Hash::make($data['password'])
            ]);

            // Eliminar el token usado
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();

            // Revocar todos los tokens existentes del usuario por seguridad
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Contraseña actualizada correctamente'
            ]);

        } catch (\Exception $e) {
            
            return response()->json([
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Cambiar contraseña estando autenticado (bonus)
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)]
        ]);

        $user = $request->user();

        // Verificar contraseña actual
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta'
            ], 400);
        }

        // Actualizar contraseña
        $user->update([
            'password' => Hash::make($data['password'])
        ]);

        // Revocar todos los otros tokens por seguridad (excepto el actual)
        $currentToken = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $currentToken->id)->delete();

        return response()->json([
            'message' => 'Contraseña cambiada correctamente'
        ]);
    }
}