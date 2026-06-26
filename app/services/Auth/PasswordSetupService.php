<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetMail;
use App\Models\Admin\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Genera y envía un enlace para que el usuario establezca su contraseña.
 *
 * Reutiliza el mismo mecanismo que la recuperación de contraseña
 * (tabla password_reset_tokens + PasswordResetMail), de modo que el enlace
 * funciona con el flujo de reset ya existente. Se usa en el alta de usuarios
 * por carga masiva, donde no se define una contraseña en el archivo.
 */
class PasswordSetupService
{
    /**
     * Encola el correo con el enlace de "establece tu contraseña".
     * El envío real lo hace el worker de colas (PasswordResetMail es ShouldQueue),
     * así la request no se bloquea esperando al SMTP.
     *
     * Best-effort: si el encolado falla, lo reporta y devuelve false sin lanzar
     * (para no interrumpir un proceso por lotes).
     */
    public function sendSetupLink(User $user): bool
    {
        try {
            $tokenPlain = Str::random(64);

            // Un único token vigente por correo
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email'      => $user->email,
                'token'      => Hash::make($tokenPlain),
                'created_at' => now(),
            ]);

            Mail::to($user->email)->queue(new PasswordResetMail($tokenPlain, $user));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
