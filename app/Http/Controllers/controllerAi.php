<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;

class controllerAi extends Controller
{
    public function generate(Request $request)
    {
        try {
            // Validar que venga el campo "prompt"
            $validated = $request->validate([
                'prompt' => 'required|string|min:3',
            ]);

            $prompt = $validated['prompt'];

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un asistente útil.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            return response()->json([
                'success' => true,
                'answer' => $response->choices[0]->message->content ?? 'Sin respuesta',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Errores de validación → 422 Unprocessable Entity
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // Otros errores → 500 Internal Server Error
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}




