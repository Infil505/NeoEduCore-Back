<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    /**
     * 1) Enviar enlace de recuperación (correo)
     * Responde siempre genérico para evitar enumeración.
     */
    public function sendResetLink(Request $request)
{
    $data = $request->validate([
        'email' => ['required', 'email', 'max:120'],
    ]);

    $email = strtolower($data['email']);

    // Respuesta genérica (evita enumeración)
    $genericResponse = response()->json([
        'message' => 'Si el correo está registrado, recibirás un enlace de recuperación'
    ]);

    try {
        $user = User::where('email', $email)->first();

        // No damos pistas si no existe
        if (!$user) {
            return $genericResponse;
        }

        // Opcional: bloquear reset si el usuario está inactivo/suspendido
        if (method_exists($user, 'getAttribute') && isset($user->status) && $user->status !== \App\Enums\UserStatus::Active) {
            return $genericResponse;
        }

        // Token plano para el link del correo
        $tokenPlain = Str::random(64);

        // Limpiar tokens anteriores
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Guardar hash del token
        DB::table('password_reset_tokens')->insert([
            'email'      => $email,
            'token'      => Hash::make($tokenPlain),
            'created_at' => now(),
        ]);

        /**
         * Link SOLO backend (Blade)
         * IMPORTANTE: route() depende de APP_URL para armar el host correcto.
         * Si a veces te sale localhost en prod, es porque APP_URL está mal.
         */
        $resetUrl = url('/password/reset/' . $tokenPlain) . '?email=' . urlencode($email);

        // Enviar correo
        Mail::to($email)->send(new PasswordResetMail($resetUrl, $user));

        return $genericResponse;

    } catch (\Throwable $e) {
        // Laravel style
        report($e);

        return response()->json(['message' => 'Error interno del servidor'], 500);
    }
}


    /**
     * 2) Mostrar formulario Blade (solo accesible con link del correo)
     * Ruta en web.php:
     *   GET /password/reset/{token}?email=...
     */
    public function showResetForm(string $token, Request $request)
    {
        $email = strtolower((string) $request->query('email'));

        if (!$email) {
            abort(403);
        }

        $passwordReset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$passwordReset) {
            abort(403);
        }

        if (!Hash::check($token, $passwordReset->token)) {
            abort(403);
        }

        $createdAt = Carbon::parse($passwordReset->created_at);

        // Expira a las 24h
        if (now()->diffInHours($createdAt) > 24) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            abort(403);
        }

        $user = User::where('email', $email)->first();

        return view('auth.reset-password', [
            'token' => $token,          // token plano (del link)
            'email' => $email,
            'user' => $user,
            'appName' => config('app.name'),
            'apiBaseUrl' => url('/api'), // para tu fetch en JS si lo ocupás
        ]);
    }

    /**
     * 3) Verificar token por API (opcional)
     */
    public function verifyToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:120'],
        ]);

        $email = strtolower($data['email']);

        try {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            if (!Hash::check($data['token'], $passwordReset->token)) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            $createdAt = Carbon::parse($passwordReset->created_at);

            if (now()->diffInHours($createdAt) > 24) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
                return response()->json(['message' => 'El token de reset ha expirado'], 400);
            }

            return response()->json(['message' => 'Token válido']);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * 4) Resetear contraseña (API)
     * Espera: email, token y password + password_confirmation
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:120'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $email = strtolower($data['email']);

        try {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            if (!Hash::check($data['token'], $passwordReset->token)) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            $createdAt = Carbon::parse($passwordReset->created_at);

            if (now()->diffInHours($createdAt) > 24) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
                return response()->json(['message' => 'El token de reset ha expirado'], 400);
            }

            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json(['message' => 'Token de reset inválido'], 400);
            }

            // ✅ Ajuste clave: tu esquema usa password_hash
            $user->update([
                'password_hash' => Hash::make($data['password']),
            ]);

            // Consumir token
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Revocar tokens Sanctum por seguridad
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return response()->json(['message' => 'Contraseña actualizada correctamente']);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * 5) Cambiar contraseña estando autenticado (API)
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        // ✅ Ajuste clave: comparar contra password_hash
        if (!Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta'], 400);
        }

        $user->update([
            'password_hash' => Hash::make($data['password']),
        ]);

        // Revocar tokens excepto el actual (si existe)
        if (method_exists($user, 'tokens')) {
            $currentToken = $user->currentAccessToken();
            if ($currentToken) {
                $user->tokens()->where('id', '!=', $currentToken->id)->delete();
            }
        }

        return response()->json(['message' => 'Contraseña cambiada correctamente']);
    }
}