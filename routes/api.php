<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\controllerAi;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;

Route::post('/ai/generate', [controllerAi::class, 'generate']);
Route::get('/ping', fn () => response()->json(['ok' => true]));

// =====================
//  REGISTRO (fuera de /auth)
// =====================
Route::post('/register', [AuthController::class, 'register'])->name('register');

// =====================
//  LOGIN + RUTAS AUTENTICADAS (dentro de /auth)
// =====================
Route::prefix('auth')->group(function () {
    // Autenticación
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    // Rutas protegidas con Sanctum
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::prefix('password')->group(function () {
    // Enviar enlace de recuperación
    Route::post('/forgot', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    // Verificar token de restablecimiento (opcional si tu flujo lo requiere)
    Route::post('/verify', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:10,1')
        ->name('password.verify');

    // Restablecer contraseña
    Route::post('/reset', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset');
});
