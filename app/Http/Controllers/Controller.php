<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'NeoEduCore API', version: '1.0.0', description: 'API REST del backend NeoEduCore para la plataforma educativa.')]
#[OA\SecurityScheme(securityScheme: 'sanctum', type: 'http', scheme: 'bearer', bearerFormat: 'JWT')]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Servidor local')]
abstract class Controller
{
    //
}
