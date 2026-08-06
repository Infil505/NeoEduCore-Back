<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

/*
 | Estos atributos solo los lee `l5-swagger:generate`, que YA NO es el
 | generador del proyecto: el documento lo produce `openapi:generate` desde
 | las rutas reales (ver App\Console\Commands\OpenApiGenerate). Se conservan
 | porque documentan la API a nivel de código, pero no alimentan nada.
 */
#[OA\Info(title: 'NeoEduCore API', version: '1.0.0', description: 'API REST del backend NeoEduCore para la plataforma educativa.')]
// `bearerFormat` describía el token como JWT y no lo es: Sanctum emite tokens
// **opacos** guardados en `personal_access_tokens`. El informe del TFG comete el
// mismo error (ver docs/ANALISIS_MODELO_DATOS_TFG.md §9.1 nº 4), así que la
// documentación generada lo estaba respaldando.
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    description: 'Token opaco de Laravel Sanctum. Se obtiene en POST /api/auth/login y se envía como `Authorization: Bearer <token>`.'
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Servidor local')]
abstract class Controller
{
    //
}
