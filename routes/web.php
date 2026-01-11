<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ForgotPasswordController;

// routes/web.php
Route::get('/password/reset/{token}', [ForgotPasswordController::class, 'showResetForm'])
    ->name('password.reset.form');