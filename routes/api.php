<?php

use App\Http\Controllers\controllerAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\AuthController;

Route::post('/ai/generate', [controllerAi::class, 'generate']);

Route::get('/ping', fn() => response()->json(['ok' => true]));

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('auth')->group(function () {
    // Enviar enlace de recuperación
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:5,1') // Máximo 5 intentos por minuto
        ->name('password.email');
    
    // Verificar si un token es válido
    Route::post('verify-reset-token', [ForgotPasswordController::class, 'verifyToken'])
        ->middleware('throttle:10,1')
        ->name('password.verify');
    
    // Resetear contraseña
    Route::post('reset-password', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset');
});