<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Illuminate\Http\Request;

class InstitutionController extends Controller
{
    /**
     * Listar instituciones (para panel admin SaaS)
     */
    public function index()
    {
        return response()->json([
            'data' => Institution::query()
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    /**
     * Ver institución
     */
    public function show(Institution $institution)
    {
        return response()->json([
            'data' => $institution,
        ]);
    }

    /**
     * Actualizar institución
     * Nota: el código se guarda en mayúsculas por el mutator del modelo
     */
    public function update(Request $request, Institution $institution)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['address'])) {
            $data['address'] = trim($data['address']);
        }

        $institution->fill($data);
        $institution->save();

        return response()->json([
            'data' => $institution->fresh(),
        ]);
    }

    /**
     * Activar / Desactivar institución (atajo)
     */
    public function toggleStatus(Institution $institution)
    {
        $institution->is_active = !$institution->is_active;
        $institution->save();

        return response()->json([
            'data' => $institution->fresh(),
        ]);
    }
}