<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Institution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemConfigController extends Controller
{
    /**
     * GET /api/system/config
     * Devuelve la configuración de la institución actual (defaults + overrides guardados).
     */
    public function show(Request $request)
    {
        $institution = Institution::findOrFail($request->user()->institution_id);

        $config = array_merge(
            Institution::$defaultSettings,
            $institution->settings ?? []
        );

        return response()->json([
            'data' => [
                'institution_id'      => $institution->id,
                'institution_name'    => $institution->name,
                'institution_address' => $institution->address,
                'institution_phone'   => $institution->phone,
                'config'              => $config,
            ],
        ]);
    }

    /**
     * PUT /api/system/config
     * Actualiza la configuración de la institución. Solo admin.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            // Identidad del centro: columnas reales de `institutions`, no settings.
            'name'               => ['sometimes', 'string', 'min:2', 'max:120'],
            'address'            => ['nullable', 'string', 'max:200'],
            'phone'              => ['nullable', 'string', 'max:30'],
            'timezone'           => ['sometimes', 'string', 'timezone'],
            'language'           => ['sometimes', 'string', Rule::in(['es', 'en'])],
            'logo_url'           => ['nullable', 'url', 'max:500'],
            'max_exam_duration'  => ['sometimes', 'integer', 'between:5,300'],
            'allow_registration' => ['sometimes', 'boolean'],
            'contact_email'      => ['nullable', 'email', 'max:120'],
            // Nota mínima de aprobación de los reportes. El tope de 100 evita
            // dejar la institución con todos los exámenes reprobados por error.
            'passing_percentage' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        $institution = Institution::findOrFail($request->user()->institution_id);

        $identity = array_intersect_key($data, array_flip(['name', 'address', 'phone']));
        $settingsData = array_diff_key($data, $identity);

        if (isset($identity['name'])) {
            $identity['name'] = trim($identity['name']);
        }

        if (!empty($identity)) {
            $institution->fill($identity);
        }

        $current = $institution->settings ?? [];
        $institution->settings = array_merge($current, $settingsData);
        $institution->save();

        $config = array_merge(Institution::$defaultSettings, $institution->fresh()->settings ?? []);

        return response()->json([
            'data' => [
                'institution_id'   => $institution->id,
                'institution_name' => $institution->name,
                'institution_address' => $institution->address,
                'institution_phone'   => $institution->phone,
                'config'           => $config,
            ],
        ]);
    }
}
