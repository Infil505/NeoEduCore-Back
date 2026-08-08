<?php

namespace Tests\Feature\Crud;

use App\Mail\PasswordSetupMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class BulkUploadStudentsTest extends TestCase
{
    use ApiAuth;

    private const HEADER = 'full_name,email,user_id,student_code,grade,section,status,birth_date,parent_name,parent_email,group_code,adecuacion_type';

    private function uploadCsv(string $csv)
    {
        $file = UploadedFile::fake()->createWithContent('estudiantes.csv', $csv);

        return $this->post('/api/students/bulk-upload', ['file' => $file]);
    }

    public function test_bulk_upload_creates_users_and_queues_setup_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.solis@ejemplo.com,,EST-0001,10,A,active,,,,,\n"
            . "Luis Mora,luis.mora@ejemplo.com,,EST-0002,11,B,active,,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created'       => 2,
            'users_created' => 2,
            'emails_queued' => 2,
            'skipped'       => 0,
        ]);

        // Usuarios y perfiles creados en la institución del admin
        $this->assertDatabaseHas('users', [
            'email'          => 'ana.solis@ejemplo.com',
            'institution_id' => $institution->id,
            'user_type'      => 'student',

            // Nace INACTIVA: la activa su dueña al definir la contraseña desde
            // el correo. Antes se creaba ya activa, y en el panel no había forma
            // de saber quién había completado el alta y quién no.
            'status'         => 'inactive',
        ]);
        $ana = User::where('email', 'ana.solis@ejemplo.com')->first();
        $this->assertDatabaseHas('students', [
            'user_id'        => $ana->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-0001',
        ]);

        // El Mailable es ShouldQueue → se registra como encolado, no como enviado
        // PasswordSetupMail y no PasswordResetMail: a estos usuarios les
        // crearon la cuenta, no pidieron recuperar su contraseña. Los dos
        // flujos compartían Mailable y el texto solo servía para el alta.
        Mail::assertQueued(PasswordSetupMail::class, 2);
        Mail::assertNotQueued(\App\Mail\PasswordResetMail::class);
    }

    public function test_bulk_upload_requires_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.solis@ejemplo.com,,EST-0001,10,A,active,,,,,\n";

        $this->uploadCsv($csv)->assertStatus(403);
    }

    public function test_bulk_upload_links_existing_user_without_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        // Usuario existente (mismo tenant) sin perfil de estudiante todavía
        $existing = User::factory()->student()->create(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . ",,{$existing->id},EST-9001,9,C,active,,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created'       => 1,
            'users_created' => 0, // no se creó cuenta, se reusó la existente
            'emails_queued' => 0,
            'skipped'       => 0,
        ]);

        $this->assertDatabaseHas('students', [
            'user_id'      => $existing->id,
            'student_code' => 'EST-9001',
        ]);

        Mail::assertNothingQueued();
    }

    public function test_bulk_upload_rejects_cross_tenant_user_id(): void
    {
        Mail::fake();

        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institutionA->id]);

        // Usuario de OTRA institución
        $foreign = User::factory()->student()->create(['institution_id' => $institutionB->id]);

        $csv = self::HEADER . "\n"
            . ",,{$foreign->id},EST-7777,8,A,active,,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created' => 0,
            'skipped' => 1,
        ]);

        // No se creó perfil para el usuario ajeno
        $this->assertDatabaseMissing('students', [
            'user_id' => $foreign->id,
        ]);
    }
}
