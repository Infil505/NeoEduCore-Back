<?php

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Alcance del superadmin: instituciones y sus administradores, **nada más**.
 *
 * El rol es externo a las instituciones y no tiene `institution_id`. Esa
 * ausencia no es cosmética: los modelos con `TenantScoped` exigen un
 * `tenant_id` que él nunca tiene, así que aunque una ruta se abriera por error
 * tampoco podría leer datos académicos.
 */
class SuperAdminScopeTest extends TestCase
{
    use ApiAuth;

    public function test_superadmin_has_no_institution(): void
    {
        $super = $this->signInSuperAdmin();

        $this->assertNull($super->institution_id);
        $this->assertSame(UserType::SuperAdmin, $super->user_type);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.user_type', 'superadmin')
            ->assertJsonPath('user.institution_id', null);
    }

    /**
     * El corazón del rol: puede administrar el SaaS y no puede mirar dentro de
     * ningún centro.
     */
    public function test_superadmin_is_locked_out_of_all_academic_data(): void
    {
        $this->signInSuperAdmin();

        foreach ([
            '/api/students',
            '/api/groups',
            '/api/subjects',
            '/api/exams',
            '/api/users',
            '/api/student-progress',
            '/api/ai-recommendations',
            '/api/analytics/institution',
            '/api/teacher-assignments',
            '/api/system/config',
        ] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }
    }

    public function test_superadmin_cannot_create_users_through_register(): void
    {
        $this->signInSuperAdmin();

        $this->postJson('/api/register', [
            'full_name'             => 'Alguien',
            'email'                 => 'alguien@ejemplo.com',
            'password'              => 'Abcdefg1',
            'password_confirmation' => 'Abcdefg1',
        ])->assertForbidden();
    }

    /**
     * Un admin de centro no puede fabricarse un operador de plataforma: el rol
     * no está entre los asignables desde `/register`.
     */
    public function test_institution_admin_cannot_create_a_superadmin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/register', [
            'full_name'             => 'Falso operador',
            'email'                 => 'falso@ejemplo.com',
            'password'              => 'Abcdefg1',
            'password_confirmation' => 'Abcdefg1',
            'user_type'             => 'superadmin',
        ])->assertStatus(422)->assertJsonValidationErrors('user_type');

        $this->assertDatabaseMissing('users', ['email' => 'falso@ejemplo.com']);
    }

    // -------------------------------------------------------------------------
    // Administradores de institución
    // -------------------------------------------------------------------------

    public function test_superadmin_creates_an_institution_admin_as_inactive(): void
    {
        Notification::fake();
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();

        $res = $this->postJson("/api/institutions/{$institution->id}/admins", [
            'full_name' => 'Directora',
            'email'     => 'directora@centro.com',
        ])->assertCreated();

        // Nace inactiva: el acceso lo abre el enlace, nadie conoce la contraseña.
        $this->assertDatabaseHas('users', [
            'email'          => 'directora@centro.com',
            'user_type'      => 'admin',
            'status'         => 'inactive',
            'institution_id' => $institution->id,
        ]);

        $this->assertSame('admin', $res->json('data.admin.user_type'));
    }

    public function test_admin_listing_only_returns_admins(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();
        $admin   = User::factory()->admin()->create(['institution_id' => $institution->id]);
        $docente = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $res = $this->getJson('/api/institution-admins')->assertOk();
        $ids = collect($res->json('data.data'))->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
        $this->assertNotContains($docente->id, $ids);
    }

    /**
     * Las rutas de administradores no sirven para tocar otros roles: sin este
     * filtro darían acceso a cualquier cuenta de cualquier centro.
     */
    public function test_admin_routes_cannot_touch_a_teacher_or_a_student(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();
        $docente = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $alumno  = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);

        foreach ([$docente, $alumno] as $ajeno) {
            $this->getJson("/api/institution-admins/{$ajeno->id}")->assertNotFound();
            $this->putJson("/api/institution-admins/{$ajeno->id}", ['full_name' => 'Cambiado'])->assertNotFound();
            $this->deleteJson("/api/institution-admins/{$ajeno->id}")->assertNotFound();
        }

        $this->assertDatabaseHas('users', ['id' => $docente->id, 'user_type' => 'teacher']);
    }

    public function test_cannot_delete_the_only_admin_of_an_institution(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();
        $unico = User::factory()->admin()->create(['institution_id' => $institution->id]);

        $this->deleteJson("/api/institution-admins/{$unico->id}")->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $unico->id]);
    }

    public function test_can_delete_an_admin_when_another_one_remains(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();
        $primero = User::factory()->admin()->create(['institution_id' => $institution->id]);
        $segundo = User::factory()->admin()->create(['institution_id' => $institution->id]);

        $this->deleteJson("/api/institution-admins/{$segundo->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $segundo->id]);
        $this->assertDatabaseHas('users', ['id' => $primero->id]);
    }

    public function test_institution_admin_cannot_reach_the_admin_management_routes(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $otro = User::factory()->admin()->create(['institution_id' => $institution->id]);

        $this->getJson('/api/institution-admins')->assertForbidden();
        $this->putJson("/api/institution-admins/{$otro->id}", ['full_name' => 'X'])->assertForbidden();
        $this->postJson("/api/institutions/{$institution->id}/admins", [
            'full_name' => 'Nuevo', 'email' => 'nuevo@centro.com',
        ])->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // El rol `parent` ya no existe
    // -------------------------------------------------------------------------

    public function test_parent_role_is_gone_from_the_enum(): void
    {
        $valores = array_map(fn ($c) => $c->value, UserType::cases());

        $this->assertNotContains('parent', $valores);
        $this->assertContains('superadmin', $valores);
        $this->assertSame(['admin', 'teacher', 'student'], UserType::rolesDeInstitucion());
    }

    public function test_register_rejects_the_parent_role(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/register', [
            'full_name'             => 'Acudiente',
            'email'                 => 'acudiente@ejemplo.com',
            'password'              => 'Abcdefg1',
            'password_confirmation' => 'Abcdefg1',
            'user_type'             => 'parent',
        ])->assertStatus(422)->assertJsonValidationErrors('user_type');
    }
}
