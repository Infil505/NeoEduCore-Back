<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\controllerAi;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;

Route::get('/ping', fn () => response()->json(['ok' => true]));

// AI
Route::post('/ai/generate', [controllerAi::class, 'generate']);

// =====================
// AUTH (API)
// =====================
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

// Rutas protegidas con Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');

    // Cambiar contraseña logueado (opcional, si lo tenés en el controller)
    Route::post('/password/change', [ForgotPasswordController::class, 'changePassword'])
        ->middleware('throttle:5,1')
        ->name('password.change');
});

// =====================
// PASSWORD RESET (API)
// =====================
Route::prefix('password')->group(function () {
    // Enviar enlace de recuperación (correo)
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    // Verificar token (opcional)
    Route::post('/verify', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:10,1')
        ->name('password.verify');

    // Restablecer contraseña (API)
    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset');

    // Enviar correo
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink']);

    // Resetear contraseña (lo usa el Blade vía fetch)
    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword']);
});