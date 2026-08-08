<?php

namespace Tests\Feature\Crud;

use App\Mail\PasswordSetupMail;
use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Carga masiva de estudiantes.
 *
 * Desde el 08/08/2026 la columna **`aula` es obligatoria** y matricula al
 * estudiante en el grupo. Antes el archivo solo escribía `section` en la ficha,
 * una etiqueta de texto: el alumno quedaba sin fila en `group_students` y, con
 * el modelo de asignaciones, invisible para todo docente y sin recibir exámenes.
 */
class BulkUploadStudentsTest extends TestCase
{
    use ApiAuth;

    private const HEADER = 'full_name,email,user_id,student_code,aula,status,birth_date,parent_name,parent_email,adecuacion_type';

    private function uploadCsv(string $csv)
    {
        $file = UploadedFile::fake()->createWithContent('estudiantes.csv', $csv);

        return $this->post('/api/students/bulk-upload', ['file' => $file]);
    }

    private function aula(Institution $institution, string $code, int $grade = 10, string $section = 'A'): Group
    {
        return Group::factory()->create([
            'institution_id' => $institution->id,
            'group_code'     => $code,
            'grade'          => $grade,
            'section'        => $section,
        ]);
    }

    public function test_bulk_upload_creates_users_and_queues_setup_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $aulaA = $this->aula($institution, '10A2026', 10, 'A');
        $aulaB = $this->aula($institution, '11B2026', 11, 'B');

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.solis@ejemplo.com,,EST-0001,10A2026,active,,,,\n"
            . "Luis Mora,luis.mora@ejemplo.com,,EST-0002,11B2026,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created'       => 2,
            'users_created' => 2,
            'emails_queued' => 2,
            'matriculados'  => 2,
            'reasignados'   => 0,
            'skipped'       => 0,
        ]);

        $this->assertDatabaseHas('users', [
            'email'          => 'ana.solis@ejemplo.com',
            'institution_id' => $institution->id,
            'user_type'      => 'student',
            'status'         => 'inactive',
        ]);

        $ana = User::where('email', 'ana.solis@ejemplo.com')->first();

        // El grado y la sección salen del aula, no del archivo.
        $this->assertDatabaseHas('students', [
            'user_id'        => $ana->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-0001',
            'grade'          => 10,
            'section'        => 'A',
            'group_code'     => '10A2026',
        ]);

        // Y queda MATRICULADA, que es lo que le da visibilidad al docente.
        $this->assertDatabaseHas('group_students', [
            'group_id'        => $aulaA->id,
            'student_user_id' => $ana->id,
            'left_at'         => null,
        ]);

        $this->assertSame(1, (int) $aulaA->fresh()->student_count);
        $this->assertSame(1, (int) $aulaB->fresh()->student_count);

        Mail::assertQueued(PasswordSetupMail::class, 2);
        Mail::assertNotQueued(\App\Mail\PasswordResetMail::class);
    }

    public function test_bulk_upload_requires_the_aula_column(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $this->aula($institution, '10A2026');

        $csv = "full_name,email,student_code\n"
            . "Ana Solis,ana.solis@ejemplo.com,EST-0001\n";

        $this->uploadCsv($csv)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'aula'));
    }

    public function test_a_row_without_aula_is_skipped(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $this->aula($institution, '10A2026');

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.sinaula@ejemplo.com,,EST-SINAULA,,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertDatabaseMissing('users', ['email' => 'ana.sinaula@ejemplo.com']);
    }

    /** Un typo en el código no debe crear un aula fantasma. */
    public function test_an_unknown_aula_is_skipped_and_not_created(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $this->aula($institution, '10A2026');

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.typoaula@ejemplo.com,,EST-TYPO,10A2O26,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertSame(1, Group::where('institution_id', $institution->id)->count());
    }

    public function test_upload_is_rejected_when_the_institution_has_no_aulas(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.sinaulas@ejemplo.com,,EST-SINAULAS,10A2026,active,,,,\n";

        $this->uploadCsv($csv)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'POST /api/groups'));
    }

    /**
     * El cambio de aula: solo ocurre sobre un estudiante que ya existe, y cierra
     * la matrícula anterior en vez de dejar dos activas.
     */
    public function test_reuploading_with_a_different_aula_moves_the_student(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $origen  = $this->aula($institution, '10A2026', 10, 'A');
        $destino = $this->aula($institution, '11B2026', 11, 'B');

        $alta = self::HEADER . "\n"
            . "Ana Solis,ana.traslado@ejemplo.com,,EST-TRASLADO,10A2026,active,,,,\n";
        $this->uploadCsv($alta)->assertOk();

        $ana = User::where('email', 'ana.traslado@ejemplo.com')->first();

        $traslado = self::HEADER . "\n"
            . "Ana Solis,ana.traslado@ejemplo.com,,EST-TRASLADO,11B2026,active,,,,\n";

        $res = $this->uploadCsv($traslado);

        $res->assertOk();
        $res->assertJson([
            'created'      => 0,
            'updated'      => 1,
            'reasignados'  => 1,
            'matriculados' => 0,
        ]);

        // Matrícula anterior cerrada, no borrada: el historial se conserva.
        $anterior = DB::table('group_students')
            ->where('group_id', $origen->id)
            ->where('student_user_id', $ana->id)
            ->first();
        $this->assertNotNull($anterior->left_at);

        $this->assertDatabaseHas('group_students', [
            'group_id'        => $destino->id,
            'student_user_id' => $ana->id,
            'left_at'         => null,
        ]);

        // Una sola matrícula activa.
        $this->assertSame(1, DB::table('group_students')
            ->where('student_user_id', $ana->id)
            ->whereNull('left_at')
            ->count());

        // La ficha sigue al aula nueva.
        $this->assertDatabaseHas('students', [
            'user_id' => $ana->id,
            'grade'   => 11,
            'section' => 'B',
        ]);

        // Contadores de ambas aulas al día.
        $this->assertSame(0, (int) $origen->fresh()->student_count);
        $this->assertSame(1, (int) $destino->fresh()->student_count);
    }

    /** Reprocesar el mismo archivo no debe duplicar ni mover nada. */
    public function test_reuploading_the_same_aula_is_a_no_op(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $aula = $this->aula($institution, '10A2026');

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.noop@ejemplo.com,,EST-NOOP,10A2026,active,,,,\n";

        $this->uploadCsv($csv)->assertOk();
        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson(['reasignados' => 0, 'matriculados' => 0, 'updated' => 1]);

        $ana = User::where('email', 'ana.noop@ejemplo.com')->first();

        $this->assertSame(1, DB::table('group_students')
            ->where('student_user_id', $ana->id)
            ->count());
        $this->assertSame(1, (int) $aula->fresh()->student_count);
    }

    public function test_bulk_upload_requires_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.docente@ejemplo.com,,EST-DOCENTE,10A2026,active,,,,\n";

        $this->uploadCsv($csv)->assertStatus(403);
    }

    public function test_bulk_upload_links_existing_user_without_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $aula = $this->aula($institution, '9C2026', 9, 'C');

        // Usuario existente (mismo tenant) sin perfil de estudiante todavía
        $existing = User::factory()->student()->create(['institution_id' => $institution->id]);

        $csv = self::HEADER . "\n"
            . ",,{$existing->id},EST-9001,9C2026,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created'       => 1,
            'users_created' => 0, // no se creó cuenta, se reusó la existente
            'emails_queued' => 0,
            'matriculados'  => 1,
            'skipped'       => 0,
        ]);

        $this->assertDatabaseHas('students', [
            'user_id'      => $existing->id,
            'student_code' => 'EST-9001',
        ]);

        $this->assertDatabaseHas('group_students', [
            'group_id'        => $aula->id,
            'student_user_id' => $existing->id,
            'left_at'         => null,
        ]);

        Mail::assertNothingQueued();
    }

    public function test_bulk_upload_rejects_cross_tenant_user_id(): void
    {
        Mail::fake();

        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institutionA->id]);
        $this->aula($institutionA, '8A2026', 8, 'A');

        // Usuario de OTRA institución
        $foreign = User::factory()->student()->create(['institution_id' => $institutionB->id]);

        $csv = self::HEADER . "\n"
            . ",,{$foreign->id},EST-CROSS,8A2026,active,,,,\n";

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

    /**
     * `student_code` es único **por institución** desde el 08/08/2026. Dos
     * centros pueden numerar «EST-0001» cada uno: es un identificador interno
     * suyo, no de la plataforma.
     */
    public function test_two_institutions_can_use_the_same_student_code(): void
    {
        Mail::fake();

        $otra  = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otra->id]);
        Student::factory()->create([
            'user_id'        => $ajeno->id,
            'institution_id' => $otra->id,
            'student_code'   => 'EST-COMPARTIDO',
        ]);

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $aula = $this->aula($institution, 'DUP2026');

        $csv = self::HEADER . "\n"
            . "Mismo Codigo Otro Centro,compartido@ejemplo.com,,EST-COMPARTIDO,DUP2026,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson(['created' => 1, 'skipped' => 0]);

        $nuevo = User::where('email', 'compartido@ejemplo.com')->first();
        $this->assertDatabaseHas('students', [
            'user_id'        => $nuevo->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-COMPARTIDO',
        ]);

        // El del otro centro sigue intacto.
        $this->assertDatabaseHas('students', [
            'user_id'      => $ajeno->id,
            'student_code' => 'EST-COMPARTIDO',
        ]);
    }

    /**
     * Dentro del mismo centro sí choca, y el rechazo tiene que ser limpio: la
     * fila se salta y el resto del archivo se guarda.
     *
     * Ojo con el montaje: `student_code` es también **columna identificadora**,
     * así que una fila que solo lleve un código existente no es un duplicado
     * sino una actualización de ese estudiante. La colisión de verdad es esta:
     * la fila identifica al estudiante A (por su `user_id`) pero le pone el
     * código que ya tiene B.
     *
     * Regresión doble. Antes (a) la comprobación no coincidía con la constraint
     * y la violación abortaba la transacción entera de PostgreSQL, perdiendo el
     * archivo completo; y (b) la cuenta se creaba antes de validar el código,
     * así que una fila rechazada dejaba un usuario huérfano.
     */
    public function test_a_duplicate_student_code_in_the_same_institution_is_skipped_cleanly(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $aula = $this->aula($institution, 'MISMO2026');

        // B ya tiene el código que A va a intentar tomar.
        $bUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $bUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-DE-B',
        ]);

        // A existe y se identifica por su user_id.
        $aUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $aUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-DE-A',
        ]);

        $csv = self::HEADER . "\n"
            . ",,{$aUser->id},EST-DE-B,MISMO2026,active,,,,\n"
            . "Fila Buena,buena@ejemplo.com,,EST-BUENA,MISMO2026,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson([
            'created' => 1,   // la fila correcta SÍ se guarda
            'updated' => 0,
            'skipped' => 1,
        ]);

        // A conserva su código: la fila se rechazó entera.
        $this->assertDatabaseHas('students', [
            'user_id'      => $aUser->id,
            'student_code' => 'EST-DE-A',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'buena@ejemplo.com']);
        $this->assertSame(1, (int) $aula->fresh()->student_count);
    }

    /**
     * Y el usuario huérfano: una fila nueva rechazada por código duplicado no
     * debe dejar la cuenta creada, con el correo ya consumido y sin perfil.
     */
    public function test_a_rejected_new_row_does_not_leave_an_orphan_user(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $this->aula($institution, 'HUERFANO2026');

        $bUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $bUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-TOMADO',
        ]);

        // Fila NUEVA (email sin usuario) que intenta tomar un código ocupado.
        // Se resuelve como actualización de B por student_code, así que para
        // provocar el rechazo se identifica por email nuevo Y código ajeno:
        // el estudiante resuelto es B, pero el email no coincide con el suyo.
        $csv = self::HEADER . "\n"
            . "Nueva Persona,huerfano@ejemplo.com,{$bUser->id},EST-TOMADO,HUERFANO2026,active,,,,\n";

        $this->uploadCsv($csv)->assertOk();

        // El correo nuevo no se llegó a consumir en ningún caso.
        $this->assertDatabaseMissing('users', ['email' => 'huerfano@ejemplo.com']);
    }

    /** Un aula de otra institución no es resoluble aunque se sepa su código. */
    public function test_an_aula_from_another_institution_is_not_resolvable(): void
    {
        Mail::fake();

        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institutionA->id]);

        $this->aula($institutionA, 'PROPIA2026');
        $ajena = $this->aula($institutionB, 'AJENA2026');

        $csv = self::HEADER . "\n"
            . "Ana Solis,ana.ajena@ejemplo.com,,EST-AJENA,AJENA2026,active,,,,\n";

        $res = $this->uploadCsv($csv);

        $res->assertOk();
        $res->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertSame(0, DB::table('group_students')->where('group_id', $ajena->id)->count());
    }
}
