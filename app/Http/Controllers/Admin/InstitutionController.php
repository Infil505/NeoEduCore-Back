<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Gestión de instituciones — **solo superadmin**.
 *
 * Antes estaba bajo `role:admin` y `index()` no filtraba por institución: el
 * administrador de un centro listaba **todos** los centros del SaaS, con su
 * código, dirección, teléfono y correo. Es el motivo de que exista el rol
 * superadmin.
 *
 * El administrador de institución no entra aquí en absoluto. Su institución la
 * gestiona por `GET`/`PUT /api/system/config`, que lee y escribe
 * `institutions.settings` acotado a su propio `institution_id`: la frontera es
 * **datos de negocio del SaaS** (alta, baja, nombre, código) frente a
 * **configuración operativa del centro** (zona horaria, idioma, nota de corte).
 */
class InstitutionController extends Controller
{
    /**
     * Listar instituciones (panel del operador de la plataforma).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q'         => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = Institution::query()->orderByDesc('created_at');

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%"));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Alta de institución. Es el primer paso del alta de un centro: después se
     * le crea su administrador con `POST /api/institutions/{institution}/admins`.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code'      => ['required', 'string', 'max:40', Rule::unique('institutions', 'code')],
            'name'      => ['required', 'string', 'min:2', 'max:120'],
            'address'   => ['nullable', 'string', 'max:200'],
            'phone'     => ['nullable', 'string', 'max:30'],
            'email'     => ['nullable', 'email', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $institution = Institution::create([
            'code'      => $data['code'],
            'name'      => trim($data['name']),
            'address'   => isset($data['address']) ? trim($data['address']) : null,
            'phone'     => $data['phone'] ?? null,
            'email'     => $data['email'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $institution], 201);
    }

    /**
     * Ver institución, con el recuento de cuentas que sostiene.
     */
    public function show(Institution $institution)
    {
        return response()->json([
            'data' => [
                'institution' => $institution,
                'usuarios'    => $this->recuentoDeUsuarios($institution),
            ],
        ]);
    }

    /**
     * Actualizar institución
     * Nota: el código se guarda en mayúsculas por el mutator del modelo
     */
    public function update(Request $request, Institution $institution)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('institutions', 'code')->ignore($institution->id)],
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

    /**
     * Borrar una institución con todo lo que cuelga de ella.
     *
     * ⚠️ **Irreversible y sin confirmación por parte del servidor.** Las FK de
     * las 18 tablas de dominio están en `ON DELETE CASCADE`, así que esto se
     * lleva estudiantes, materias, grupos, exámenes, preguntas, intentos,
     * respuestas, progreso, recomendaciones y sesiones del tutor del centro
     * entero. Decisión explícita del proyecto (08/08/2026); la alternativa era
     * rechazar el borrado mientras quedaran usuarios.
     *
     * Los usuarios se borran **a mano y primero**: `users.institution_id` es
     * `ON DELETE SET NULL`, no CASCADE, así que la cascada sola dejaría las
     * cuentas huérfanas con `institution_id = NULL` —capaces de autenticarse,
     * sin institución y sin sus datos—. Y `NULL` es precisamente la marca del
     * superadmin, así que además quedarían indistinguibles de él por ese campo.
     */
    public function destroy(Request $request, Institution $institution)
    {
        $recuento = $this->recuentoDeUsuarios($institution);

        DB::transaction(function () use ($institution) {
            // Antes que la institución: si no, la FK SET NULL los desvincula y
            // sobreviven sin centro.
            User::where('institution_id', $institution->id)->delete();

            $institution->delete();
        });

        // El borrado no deja rastro en ninguna tabla, así que el registro es la
        // única traza de quién eliminó qué.
        Log::warning('Institución eliminada por un superadmin', [
            'institution_id'   => $institution->id,
            'institution_code' => $institution->code,
            'institution_name' => $institution->name,
            'superadmin_id'    => $request->user()->id,
            'usuarios'         => $recuento,
        ]);

        return response()->json([
            'message' => 'Institución eliminada con todos sus datos.',
            'data'    => [
                'institution_id'    => $institution->id,
                'usuarios_borrados' => $recuento,
            ],
        ]);
    }

    /** @return array<string,int> */
    private function recuentoDeUsuarios(Institution $institution): array
    {
        $porRol = User::where('institution_id', $institution->id)
            ->selectRaw('user_type, count(*) n')
            ->groupBy('user_type')
            ->pluck('n', 'user_type')
            ->all();

        return [
            'admin'   => (int) ($porRol['admin'] ?? 0),
            'teacher' => (int) ($porRol['teacher'] ?? 0),
            'student' => (int) ($porRol['student'] ?? 0),
            'total'   => array_sum($porRol),
        ];
    }
}
