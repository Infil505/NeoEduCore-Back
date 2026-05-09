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
                'institution_id'   => $institution->id,
                'institution_name' => $institution->name,
                'config'           => $config,
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
            'timezone'           => ['sometimes', 'string', 'timezone'],
            'language'           => ['sometimes', 'string', Rule::in(['es', 'en'])],
            'logo_url'           => ['nullable', 'url', 'max:500'],
            'max_exam_duration'  => ['sometimes', 'integer', 'between:5,300'],
            'allow_registration' => ['sometimes', 'boolean'],
            'contact_email'      => ['nullable', 'email', 'max:120'],
        ]);

        $institution = Institution::findOrFail($request->user()->institution_id);

        $current = $institution->settings ?? [];
        $institution->update(['settings' => array_merge($current, $data)]);

        $config = array_merge(Institution::$defaultSettings, $institution->fresh()->settings ?? []);

        return response()->json([
            'data' => [
                'institution_id'   => $institution->id,
                'institution_name' => $institution->name,
                'config'           => $config,
            ],
        ]);
    }
}
