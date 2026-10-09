<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin\Institution;
use App\Models\Exams\Exam;
use App\Support\TenantCache;
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
        $centro = $request->user()->institution_id;

        // Lo piden varias pantallas y cambia muy rara vez: caché por centro,
        // invalidada al guardar la institución (observador en AppServiceProvider).
        $datos = TenantCache::remember(
            $centro,
            TenantCache::CONFIG,
            'system',
            600,
            fn () => $this->datos(Institution::findOrFail($centro))
        );

        return response()->json(['data' => $datos]);
    }

    /**
     * Respuesta común de `show` y `update`.
     *
     * `config.contact_email` es la columna `institutions.email`, no un ajuste
     * aparte: hasta el 06/10/2026 vivía en `settings.contact_email` y el
     * formulario salía vacío aunque el centro tuviera correo (el que guarda el
     * superadmin al darlo de alta). Si solo existe el valor antiguo de
     * `settings`, se usa ese.
     */
    private function datos(Institution $institution): array
    {
        $config = array_merge(Institution::$defaultSettings, $institution->settings ?? []);
        $config['contact_email'] = $institution->email ?? $config['contact_email'];

        return [
            'institution_id'      => $institution->id,
            'institution_name'    => $institution->name,
            'institution_address' => $institution->address,
            'institution_phone'   => $institution->phone,
            'config'              => $config,
        ];
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

        /*
         | La duración máxima no se reajusta mientras haya exámenes activos.
         |
         | Cambiarla con alumnos presentando dejaría exámenes en curso con una
         | duración que ya no cumple la regla del centro (o, al contrario, con
         | tiempo que el director acababa de quitar). Se puede cambiar cuando todos
         | los exámenes están en borrador, publicados sin abrir, completados o con
         | la ventana de disponibilidad vencida. Volver a enviar el mismo valor no
         | cuenta como cambio. Los exámenes publicados que queden por encima del
         | nuevo límite no se pueden activar hasta acortarlos (ver
         | `ExamController::setStatus`).
         */
        if (array_key_exists('max_exam_duration', $data)) {
            $actual = (int) array_merge(Institution::$defaultSettings, $institution->settings ?? [])['max_exam_duration'];

            if ((int) $data['max_exam_duration'] !== $actual) {
                $activos = Exam::query()
                    ->where('status', ExamStatus::Active->value)
                    ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now()))
                    ->count();

                if ($activos > 0) {
                    return response()->json([
                        'message' => "No se puede cambiar la duración máxima mientras haya exámenes activos ({$activos}). "
                            . 'Espera a que terminen o ciérralos.',
                        'examenes_activos' => $activos,
                    ], 409);
                }
            }
        }

        $identity = array_intersect_key($data, array_flip(['name', 'address', 'phone']));
        $settingsData = array_diff_key($data, $identity);

        // El correo de contacto es la columna `institutions.email` (ver `datos()`).
        $current = $institution->settings ?? [];
        if (array_key_exists('contact_email', $settingsData)) {
            $identity['email'] = $settingsData['contact_email'];
            unset($settingsData['contact_email'], $current['contact_email']);
        }

        if (isset($identity['name'])) {
            $identity['name'] = trim($identity['name']);
        }

        if (!empty($identity)) {
            $institution->fill($identity);
        }

        $institution->settings = array_merge($current, $settingsData);
        $institution->save();

        return response()->json(['data' => $this->datos($institution->fresh())]);
    }
}
