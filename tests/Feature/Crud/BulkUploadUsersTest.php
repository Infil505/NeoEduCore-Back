<?php

namespace Tests\Feature\Crud;

use App\Mail\PasswordSetupMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Carga masiva por rol: plantilla de cada rol y subida de docentes y
 * administradores (los estudiantes se cubren en BulkUploadStudentsTest).
 */
class BulkUploadUsersTest extends TestCase
{
    use ApiAuth;

    private function upload(string $role, string $csv)
    {
        $file = UploadedFile::fake()->createWithContent('usuarios.csv', $csv);

        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/users/bulk-upload', ['role' => $role, 'file' => $file]);
    }

    public function test_each_role_gets_its_own_csv_template(): void
    {
        $this->signInAdmin();

        $esperado = [
            'student' => 'Nombre completo *;Correo institucional *;Sección *;Código de estudiante;',
            'teacher' => 'Nombre completo *;Correo institucional *',
            'admin'   => 'Nombre completo *;Correo institucional *',
        ];

        foreach ($esperado as $role => $cabecera) {
            $res = $this->get("/api/users/bulk-upload/template?role={$role}");
            $res->assertOk();
            $this->assertStringStartsWith($cabecera, ltrim($res->streamedContent(), "\xEF\xBB\xBF"), $role);
        }
    }

    public function test_teacher_xlsx_template_has_only_name_and_email(): void
    {
        $this->signInAdmin();

        $res = $this->get('/api/users/bulk-upload/template?role=teacher&format=xlsx');
        $res->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0);

        $this->assertSame('Docentes', $sheet->getTitle());
        $this->assertSame(['Nombre completo *', 'Correo institucional *', null], $sheet->rangeToArray('A4:C4')[0]);
    }

    public function test_teachers_are_created_inactive_and_receive_setup_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $csv = "Nombre completo *;Correo institucional *\n"
            . "Laura Vega;laura.vega@ejemplo.com\n"
            . "Mario Rojas;MARIO.ROJAS@ejemplo.com\n";

        $res = $this->upload('teacher', $csv);

        $res->assertOk();
        $res->assertJson(['created' => 2, 'emails_queued' => 2, 'skipped' => 0]);
        $this->assertDatabaseHas('users', [
            'email'          => 'mario.rojas@ejemplo.com',
            'user_type'      => 'teacher',
            'status'         => 'inactive',
            'institution_id' => $institution->id,
        ]);
        Mail::assertQueued(PasswordSetupMail::class, 2);
    }

    public function test_invalid_duplicated_and_taken_emails_are_skipped(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        User::factory()->create(['email' => 'ya.existe@ejemplo.com']);

        $csv = "Nombre completo;Correo institucional\n"
            . "Ana Admin;ana.admin@ejemplo.com\n"
            . "Repetida;ana.admin@ejemplo.com\n"
            . "Sin Correo;no-es-correo\n"
            . "Existente;ya.existe@ejemplo.com\n"
            . ";sin.nombre@ejemplo.com\n";

        $res = $this->upload('admin', $csv);

        $res->assertOk();
        $res->assertJson(['created' => 1, 'skipped' => 4]);
        $this->assertDatabaseHas('users', ['email' => 'ana.admin@ejemplo.com', 'user_type' => 'admin']);
        $this->assertDatabaseMissing('users', ['email' => 'sin.nombre@ejemplo.com']);
    }

    public function test_students_cannot_be_uploaded_through_this_endpoint(): void
    {
        $this->signInAdmin();

        $this->upload('student', "Nombre completo;Correo institucional\nAna;ana@ejemplo.com\n")
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_bulk_upload_of_users_requires_admin(): void
    {
        $this->signInTeacher();

        $this->upload('teacher', "Nombre completo;Correo institucional\nAna;ana@ejemplo.com\n")
            ->assertStatus(403);
    }
}
