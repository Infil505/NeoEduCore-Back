<?php

use App\Http\Controllers\controllerAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/ai/generate', [controllerAi::class, 'generate']);

Route::get('/ping', fn () => response()->json(['ok' => true]));