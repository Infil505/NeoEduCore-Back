<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ForgotPasswordController;

// routes/web.php
Route::get('/password/reset/{token}', [ForgotPasswordController::class, 'showResetForm'])
    ->name('password.reset.form');

// Simple Swagger UI page that loads the OpenAPI spec from /docs/openapi.yaml
Route::get('/docs', function () {
        $yamlUrl = url('/docs/openapi.yaml');
        $html = <<<'HTML'
<!doctype html>
<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>API Docs - NeoEduCore</title>
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@4/swagger-ui.css" />
    </head>
    <body>
        <div id="swagger-ui"></div>
        <script src="https://unpkg.com/swagger-ui-dist@4/swagger-ui-bundle.js"></script>
        <script>
            window.ui = SwaggerUIBundle({
                url: "{$yamlUrl}",
                dom_id: '#swagger-ui',
            });
        </script>
    </body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
})->name('swagger.ui');