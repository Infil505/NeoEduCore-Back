<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Services\Auth\PasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Administradores de institución, vistos desde el superadmin.
 *
 * Es la **segunda y última** competencia del superadmin: dar de alta un centro
 * y darle su administrador. A partir de ahí el centro se gestiona solo — los
 * docentes, estudiantes y todo el dominio académico los crea su propio admin,
 * y el superadmin no tiene ninguna ruta que se los muestre.
 *
 * Todos los métodos acotan a `user_type = admin`. No es cosmético: sin ese
 * filtro, estas mismas rutas servirían para leer o modificar cuentas de
 * estudiantes de cualquier centro, que es exactamente lo que el rol no debe
 * poder hacer.
 */
class InstitutionAdminController extends Controller
{
    /**
     * GET /api/institution-admins
     * Filtros: institution_id, status, q
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'institution_id' => ['nullable', 'uuid'],
            'status'         => ['nullable', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
            'q'              => ['nullable', 'string', 'max:120'],
        ]);

        $query = User::query()
            ->where('user_type', UserType::Admin->value)
            ->with('institution:id,code,name,is_active')
            ->orderByDesc('created_at');

        if (!empty($data['institution_id'])) {
            $query->where('institution_id', $data['institution_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(function ($w) use ($q) {
                $w->where('full_name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%");
            });
        }

        return response()->json(['data' => $query->paginate(20)]);
    }

    /**
     * POST /api/institutions/{institution}/admins
     *
     * La cuenta nace **inactiva** y se le manda el enlace para definir
     * contraseña, igual que la carga masiva de estudiantes: así nadie —tampoco
     * el superadmin— llega a conocer la contraseña del administrador de un
     * centro ajeno.
     */
    public function store(Request $request, Institution $institution, PasswordSetupService $passwordSetup)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email'     => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
        ]);

        $admin = User::create([
            'institution_id' => $institution->id,
            'email'          => strtolower(trim($data['email'])),
            'full_name'      => trim($data['full_name']),
            'user_type'      => UserType::Admin->value,
            'status'         => UserStatus::Inactive->value,
            // Contraseña aleatoria inutilizable: el acceso se abre con el enlace.
            'password_hash'  => Hash::make(Str::random(40)),
        ]);

        $correoEnviado = $passwordSetup->sendSetupLink($admin);

        return response()->json([
            'message' => 'Administrador creado. Debe definir su contraseña desde el enlace enviado.',
            'data'    => [
                'admin'           => $admin->fresh()->load('institution:id,code,name'),
                'correo_enviado'  => $correoEnviado,
            ],
        ], 201);
    }

    public function show(User $institutionAdmin)
    {
        $this->assertEsAdminDeInstitucion($institutionAdmin);

        return response()->json([
            'data' => $institutionAdmin->load('institution:id,code,name,is_active'),
        ]);
    }

    /**
     * PUT /api/institution-admins/{institutionAdmin}
     *
     * `institution_id` se puede cambiar: es el traslado de un administrador de
     * un centro a otro, que solo el superadmin puede hacer. El rol no se toca
     * desde aquí — degradar a un admin a docente es una operación del centro.
     */
    public function update(Request $request, User $institutionAdmin)
    {
        $this->assertEsAdminDeInstitucion($institutionAdmin);

        $data = $request->validate([
            'full_name'      => ['sometimes', 'string', 'min:3', 'max:120'],
            'email'          => ['sometimes', 'email', 'max:120', Rule::unique('users', 'email')->ignore($institutionAdmin->id)],
            'institution_id' => ['sometimes', 'uuid', Rule::exists('institutions', 'id')],
        ]);

        if (isset($data['full_name'])) {
            $data['full_name'] = trim($data['full_name']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $institutionAdmin->fill($data);
        $institutionAdmin->save();

        return response()->json([
            'data' => $institutionAdmin->fresh()->load('institution:id,code,name'),
        ]);
    }

    /**
     * PATCH /api/institution-admins/{institutionAdmin}/status
     */
    public function setStatus(Request $request, User $institutionAdmin)
    {
        $this->assertEsAdminDeInstitucion($institutionAdmin);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
        ]);

        $institutionAdmin->status = $data['status'];
        $institutionAdmin->save();

        return response()->json(['data' => $institutionAdmin->fresh()]);
    }

    /**
     * PATCH /api/institution-admins/{institutionAdmin}/reset-password
     *
     * Manda el enlace de definición de contraseña; no devuelve ninguna
     * credencial. Es la vía de recuperación cuando el administrador de un
     * centro pierde el acceso y no puede recuperarlo él solo.
     */
    public function resetPassword(User $institutionAdmin, PasswordSetupService $passwordSetup)
    {
        $this->assertEsAdminDeInstitucion($institutionAdmin);

        $enviado = $passwordSetup->sendSetupLink($institutionAdmin);

        return response()->json([
            'message' => 'Enlace de definición de contraseña enviado.',
            'data'    => ['correo_enviado' => $enviado],
        ]);
    }

    /**
     * DELETE /api/institution-admins/{institutionAdmin}
     *
     * Se impide dejar un centro activo sin ningún administrador: nadie podría
     * volver a gestionarlo salvo creando otro desde aquí, y el centro quedaría
     * operando a ciegas mientras tanto.
     */
    public function destroy(User $institutionAdmin)
    {
        $this->assertEsAdminDeInstitucion($institutionAdmin);

        $quedan = User::where('institution_id', $institutionAdmin->institution_id)
            ->where('user_type', UserType::Admin->value)
            ->where('id', '!=', $institutionAdmin->id)
            ->count();

        if ($quedan === 0) {
            return response()->json([
                'message' => 'Es el único administrador de su institución. Crear otro antes de eliminarlo.',
            ], 409);
        }

        $institutionAdmin->delete();

        return response()->noContent();
    }

    /**
     * 404 y no 403 si el usuario objetivo no es un admin de institución: un 403
     * confirmaría que ese id existe y qué rol tiene.
     */
    private function assertEsAdminDeInstitucion(User $user): void
    {
        if ($user->user_type !== UserType::Admin) {
            abort(404);
        }
    }
}
