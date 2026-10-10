<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use AcotaAlDocente;

    /**
     * El modelo User no usa TenantScoped (login/register públicos consultan
     * por email sin contexto de tenant), así que aquí se filtra el tenant
     * manualmente. Devuelve 404 si el usuario objetivo es de otra institución,
     * mismo criterio que el resto de recursos para no revelar su existencia.
     */
    private function assertSameTenant(Request $request, User $user): void
    {
        if ($user->institution_id !== $request->user()->institution_id) {
            abort(404);
        }
    }

    /**
     * El docente alcanza al alumnado **solo por asignación**, también por aquí.
     *
     * Este endpoint era una puerta paralela a `/students`: aquel filtraba por
     * `teacher_assignments` y este solo por institución, así que un docente sin
     * ninguna asignación listaba a todos los menores del centro con su nombre y
     * su correo, y abría la ficha de cualquiera. La regla del sistema no cambia
     * según la ruta por la que se entre. Detectado el 13/09/2026 en la revisión
     * sistemática de alcance por rol (S1).
     *
     * Se limita **solo al alumnado**. El personal del centro (docentes y
     * administración) sigue siendo visible: es un directorio interno entre
     * adultos, y la frontera que [173] protege es la de los menores.
     *
     * 404 y no 403, igual que `assertSameTenant()`: un 403 confirmaría que ese
     * estudiante existe, que es justo lo que no se quiere revelar.
     */
    private function assertPuedeVerFicha(Request $request, User $objetivo): void
    {
        $quienMira = $request->user();

        if (! $this->esDocente($quienMira) || $objetivo->user_type !== UserType::Student) {
            return;
        }

        if (! $this->docenteAlcanzaEstudiante($quienMira, $objetivo->id)) {
            abort(404);
        }
    }

    /**
     * Listar usuarios del tenant (filtrable)
     * Filtros: user_type, status, q (por nombre/email)
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'user_type' => ['nullable', Rule::in(UserType::rolesDeInstitucion())],
            'status'    => ['nullable', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
            'q'         => ['nullable', 'string', 'max:120'],
            // Solo el personal (administradores y docentes): el alumnado es mucho
            // más numeroso y tiene su propio listado, `GET /api/students`.
            'staff'     => ['nullable', 'boolean'],
        ]);

        $query = User::query()
            ->where('institution_id', $request->user()->institution_id)
            ->orderByDesc('created_at');

        // Ver `assertPuedeVerFicha()`: al docente se le recorta el alumnado a
        // los suyos; el personal del centro se le deja.
        if ($this->esDocente($request->user())) {
            $alcanzados = $this->estudiantesDelDocente($request->user()->id);

            $query->where(function ($w) use ($alcanzados) {
                $w->where('user_type', '!=', UserType::Student->value)
                  ->orWhereIn('id', $alcanzados);
            });
        }

        if ($request->boolean('staff')) {
            $query->where('user_type', '!=', UserType::Student->value);
        }

        if (!empty($data['user_type'])) {
            $query->where('user_type', $data['user_type']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['q'])) {
            // Los comodines de LIKE (% y _) se buscan como texto, no como patrón.
            $q = addcslashes(trim($data['q']), '%_\\');
            $query->where(function ($w) use ($q) {
                $w->where('full_name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%");
            });
        }

        return response()->json([
            'data' => $this->paginar($query, $request),
        ]);
    }

    /**
     * GET /api/users/directory — todo lo que necesita la pantalla «Usuarios y
     * roles» en UNA petición (solo administrador).
     *
     * Antes la pantalla hacía cuatro: el resumen (aulas), `/users` (todos),
     * `/students` y otra vez `/students`, cada una con su autenticación y sus
     * consultas. Con la base remota (~0,4 s por consulta) pasaba de los 8 s;
     * ahora son: autenticación (1, junto al token) + personal (1) + una página de
     * estudiantes con su total (1) + aulas (de la caché del centro: 0 en caliente).
     *
     * - `staff`: administradores y docentes del centro (pocos; el front los filtra
     *   en el navegador).
     * - `students`: UNA página, con búsqueda en el servidor (`q`, `page`,
     *   `per_page`). Las filas y el total salen de la misma consulta
     *   (`count(*) over()`), sin una consulta de conteo aparte.
     * - `students_only=1`: al paginar o buscar solo se piden los estudiantes.
     */
    public function directory(Request $request)
    {
        $data = $request->validate([
            'q'             => ['nullable', 'string', 'max:120'],
            'students_only' => ['nullable', 'boolean'],
        ]);

        $centro = $request->user()->institution_id;
        $porPagina = $this->porPagina($request);
        $pagina = max(1, (int) $request->query('page', 1));
        $soloAlumnos = $request->boolean('students_only');

        $personal = [];
        $aulas = [];
        if (!$soloAlumnos) {
            // Mismo catálogo (y misma clave de caché) que el resumen del personal.
            $aulas = \App\Support\TenantCache::remember(
                $centro, \App\Support\TenantCache::CATALOGO, 'overview:groups:a', 300,
                fn () => \App\Models\Academic\Group::query()
                    ->orderByDesc('year')->orderBy('grade')->orderBy('section')
                    ->get()->toArray()
            );
        }

        $base = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.institution_id', $centro)
            ->where('u.institution_id', $centro);

        if (!empty($data['q'])) {
            // Los comodines de LIKE (% y _) se buscan como texto, no como patrón.
            $q = addcslashes(trim($data['q']), '%_\\');
            $base->where(function ($w) use ($q) {
                $w->where('s.student_code', 'ilike', "%{$q}%")
                  ->orWhere('u.full_name', 'ilike', "%{$q}%")
                  ->orWhere('u.email', 'ilike', "%{$q}%");
            });
        }

        $alumnos = (clone $base)
            ->select(['s.user_id', 's.student_code', 's.section', 's.status', 'u.full_name', 'u.email', 'u.status as account_status'])
            ->selectRaw('count(*) over() as total')
            ->selectRaw('(select count(*) from students where institution_id = ?) as center_total', [$centro])
            ->orderBy('s.student_code')
            ->forPage($pagina, $porPagina);

        if ($soloAlumnos) {
            $filas = $alumnos->get();
        } else {
            // Personal y estudiantes en UN solo viaje a la base: cada consulta cuesta
            // ~0,4 s en la base remota, y son lo único que hace esta petición.
            $docentes = DB::table('users')
                ->where('institution_id', $centro)
                ->where('user_type', '!=', UserType::Student->value)
                ->orderByDesc('created_at')
                ->limit(300)
                ->select(['id', 'full_name', 'email', 'user_type', 'status', 'created_at']);

            $junto = DB::selectOne(
                "select (select coalesce(json_agg(t order by t.created_at desc), cast('[]' as json)) from ({$docentes->toSql()}) t) as personal, "
                . "(select coalesce(json_agg(r order by r.student_code), cast('[]' as json)) from ({$alumnos->toSql()}) r) as alumnos",
                array_merge($docentes->getBindings(), $alumnos->getBindings())
            );

            $personal = array_map(
                fn ($u) => \Illuminate\Support\Arr::only($u, ['id', 'full_name', 'email', 'user_type', 'status']),
                json_decode($junto->personal, true)
            );
            $filas = collect(json_decode($junto->alumnos));
        }

        $total = $filas->isNotEmpty() ? (int) $filas->first()->total : 0;
        $totalCentro = $filas->isNotEmpty() ? (int) $filas->first()->center_total : null;

        // Una página más allá del final no trae filas (y por tanto tampoco el
        // total): solo en ese caso se cuenta aparte.
        if ($filas->isEmpty() && $pagina > 1) {
            $total = (clone $base)->count();
        }

        return response()->json([
            'data' => [
                'staff'  => $personal,
                'groups' => $aulas,
                'students' => [
                    'data' => $filas->map(fn ($f) => [
                        'user_id'        => $f->user_id,
                        'student_code'   => $f->student_code,
                        'section'        => $f->section,
                        'status'         => $f->status,
                        'full_name'      => $f->full_name,
                        'email'          => $f->email,
                        'account_status' => $f->account_status,
                    ])->all(),
                    'total'        => $total,
                    'center_total' => $totalCentro,
                    'current_page' => $pagina,
                    'last_page'    => max(1, (int) ceil($total / $porPagina)),
                    'per_page'     => $porPagina,
                ],
            ],
        ]);
    }

    /**
     * Ver usuario
     */
    public function show(Request $request, User $user)
    {
        $this->assertSameTenant($request, $user);
        $this->assertPuedeVerFicha($request, $user);

        return response()->json([
            'data' => $user->load(['institution', 'studentProfile']),
        ]);
    }

    /**
     * Actualizar datos básicos (NO user_type)
     */
    public function update(Request $request, User $user)
    {
        $this->assertSameTenant($request, $user);

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email'     => ['sometimes', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'status'    => ['sometimes', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
        ]);

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['full_name'])) {
            $data['full_name'] = trim($data['full_name']);
        }

        $user->fill($data);
        $user->save();

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Cambiar status (atajo)
     */
    public function setStatus(Request $request, User $user)
    {
        $this->assertSameTenant($request, $user);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                UserStatus::Active->value,
                UserStatus::Inactive->value,
                UserStatus::Suspended->value,
            ])],
        ]);

        $user->status = $data['status'];
        $user->save();

        // Una cuenta que deja de estar activa pierde también sus sesiones abiertas
        // (el middleware `activa` ya las rechazaría; así además no quedan tokens vivos).
        if ($data['status'] !== UserStatus::Active->value) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Eliminar usuario.
     * Cascada DB: perfil Student → intentos → respuestas → progreso → recomendaciones.
     * Los exámenes creados por el usuario quedan con created_by_teacher_id = NULL.
     */
    public function destroy(Request $request, User $user)
    {
        $this->assertSameTenant($request, $user);

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta'], 409);
        }

        DB::transaction(function () use ($user) {
            $now = now();

            $user->tokens()->delete();

            // Conserva recursos y eventos existentes, pero los desacopla del autor eliminado.
            DB::table('study_resources')
                ->where('created_by', $user->id)
                ->update([
                    'created_by' => null,
                    'updated_at' => $now,
                ]);

            DB::table('calendar_events')
                ->where('created_by', $user->id)
                ->update([
                    'created_by' => null,
                    'updated_at' => $now,
                ]);

            // Los examenes del docente permanecen visibles aunque el creador ya no exista.
            DB::table('exams')
                ->where('created_by_teacher_id', $user->id)
                ->update([
                    'created_by_teacher_id' => null,
                    'updated_at' => $now,
                ]);

            $attemptIds = DB::table('exam_attempts')
                ->where('student_user_id', $user->id)
                ->pluck('id');

            if ($attemptIds->isNotEmpty()) {
                $answerIds = DB::table('student_answers')
                    ->whereIn('attempt_id', $attemptIds)
                    ->pluck('id');

                if ($answerIds->isNotEmpty()) {
                    DB::table('student_answer_options')
                        ->whereIn('student_answer_id', $answerIds)
                        ->delete();
                }

                DB::table('student_answers')
                    ->whereIn('attempt_id', $attemptIds)
                    ->delete();

                DB::table('exam_attempts')
                    ->whereIn('id', $attemptIds)
                    ->delete();
            }

            DB::table('student_progress')
                ->where('student_user_id', $user->id)
                ->delete();

            DB::table('ai_recommendations')
                ->where('student_user_id', $user->id)
                ->delete();

            DB::table('group_students')
                ->where('student_user_id', $user->id)
                ->delete();

            if ($user->studentProfile()->exists()) {
                $user->studentProfile()->delete();
            }

            $user->delete();
        });

        return response()->noContent();
    }

    /**
     * Reset password (solo admin: la ruta está en el grupo `role:admin`)
     * - Cambia el hash directamente
     * - Recomendado: invalidar tokens (opcional)
     */
    public function resetPassword(Request $request, User $user)
    {
        $this->assertSameTenant($request, $user);

        $data = $request->validate([
            'password' => [
                'required',
                Password::min(8)->mixedCase()->numbers(),
                'confirmed',
            ],
        ]);

        $user->password_hash = Hash::make($data['password']);
        // La clave la escribe el administrador y se la entrega a la persona: es
        // temporal, y quien la recibe tiene que cambiarla al entrar.
        $user->must_change_password = true;
        $user->save();

        // Opcional: revocar tokens activos
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        /*
        | **Este endpoint NO activa la cuenta, y es deliberado (decisión D6).**
        |
        | La única vía por la que una cuenta pasa a `active` es que su dueño
        | defina contraseña desde el enlace que le llegó por correo
        | (`ForgotPasswordController`): eso prueba que controla ese buzón, que es
        | justo lo que un administrador no puede acreditar en su nombre.
        |
        | Lo que sí cambia desde el 13/09/2026 es que la respuesta lo dice. Antes
        | devolvía un escueto «contraseña actualizada» y el administrador
        | entregaba al alumno una contraseña con la que no podía entrar, sin que
        | nada se lo advirtiera: la queja llegaba a soporte como «no funciona el
        | sistema».
        */
        $activa = $user->status === UserStatus::Active;

        return response()->json([
            'message' => $activa
                ? 'Contraseña actualizada y tokens revocados'
                : 'Contraseña actualizada y tokens revocados, pero la cuenta sigue sin activarse: '
                    . 'no podrá iniciar sesión hasta que su titular defina la contraseña desde el '
                    . 'enlace que recibe por correo.',
            'data' => [
                'status'            => $user->status->value,
                'can_sign_in'       => $activa,
                'activation_needed' => !$activa,
            ],
        ]);
    }
}
