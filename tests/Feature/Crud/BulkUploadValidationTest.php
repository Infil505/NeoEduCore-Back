<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Carga masiva de estudiantes: la plantilla, el formato .xlsx y la validación
 * fila a fila.
 *
 * `BulkUploadStudentsTest` cubre el camino feliz y la matrícula en aulas. Aquí
 * van las filas que se rechazan —cada una con su mensaje, sin abortar el
 * lote— y los formatos de archivo.
 */
class BulkUploadValidationTest extends TestCase
{
    use ApiAuth;

    private const HEADER = 'full_name,email,user_id,student_code,aula,status,birth_date,parent_name,parent_email,adecuacion_type';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        Group::factory()->create([
            'institution_id' => $this->institution->id,
            'group_code'     => '4A2026',
            'grade'          => 4,
            'section'        => 'A',
        ]);
    }

    private function subir(string $csv)
    {
        return $this->postCargaEstudiantes([
            'file' => UploadedFile::fake()->createWithContent('estudiantes.csv', $csv),
        ]);
    }

    /* =========================
     |  Plantilla
     ========================= */

    public function test_the_template_has_the_columns_the_upload_expects_and_can_be_uploaded_as_is(): void
    {
        $res = $this->get('/api/students/bulk-upload/template')->assertOk();

        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('plantilla_estudiantes.csv', $res->headers->get('Content-Disposition'));

        $csv = $res->streamedContent();

        // BOM para que Excel en Windows respete las tildes.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Una sola fila de cabecera, con «;» y etiquetas legibles; el «*» marca
        // las obligatorias (el lector lo ignora al interpretar la cabecera).
        $lineas = array_values(array_filter(array_map('trim', explode("\n", substr($csv, 3)))));
        $this->assertCount(1, $lineas);
        $cabecera = str_getcsv($lineas[0], ';');
        $this->assertSame(
            ['Nombre completo *', 'Correo institucional *', 'Sección *', 'Código de estudiante', 'Estado',
             'Fecha de nacimiento', 'Nombre del tutor', 'Correo del tutor', 'Tipo de adecuación'],
            $cabecera
        );

        // La plantilla y el importador deben usar las mismas columnas: un
        // archivo con esa cabecera, tal cual se descarga, tiene que importarse.
        $this->subir($lineas[0] . "\n"
            . "Plantilla Ok;plantilla.ok." . uniqid() . "@ejemplo.com;A;TPL-0001;;;;;\n")
            ->assertOk()->assertJson(['created' => 1, 'skipped' => 0]);
    }

    /* =========================
     |  Formatos
     ========================= */

    public function test_an_xlsx_file_is_imported_like_a_csv(): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        // Cabeceras con mayúsculas y espacios: se normalizan a snake_case.
        $hoja->fromArray([
            ['Full Name', 'Email', 'User Id', 'Student Code', 'Aula', 'Status', 'Birth Date', 'Parent Name', 'Parent Email', 'Adecuacion Type'],
            ['Sofía Rojas', 'sofia.rojas.xlsx@ejemplo.com', '', 'XLS-0001', '4A2026', 'active', '2017-05-04', '', '', ''],
            [null, null, null, null, null, null, null, null, null, null], // fila vacía: se salta
        ]);

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);

        $this->postCargaEstudiantes([
            'file' => new UploadedFile($ruta, 'estudiantes.xlsx', null, null, true),
        ])->assertOk()->assertJson(['created' => 1, 'skipped' => 0]);

        $this->assertDatabaseHas('users', ['email' => 'sofia.rojas.xlsx@ejemplo.com', 'user_type' => 'student']);
        @unlink($ruta);
    }

    public function test_an_unsupported_or_unreadable_file_is_rejected(): void
    {
        $this->postCargaEstudiantes([
            'file' => UploadedFile::fake()->createWithContent('estudiantes.pdf', '%PDF-1.4'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Extensión .xlsx pero contenido que no es un libro de Excel.
        $this->postCargaEstudiantes([
            'file' => UploadedFile::fake()->createWithContent('estudiantes.xlsx', 'esto no es un xlsx'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    /* =========================
     |  Validación por fila
     ========================= */

    public function test_each_invalid_row_is_skipped_with_its_own_message_and_the_rest_is_imported(): void
    {
        // Una cuenta del MISMO centro con ese correo se vincularía (es correcto);
        // el rechazo es para un correo que ya usa otra institución.
        $existente = User::factory()->student()->create([
            'institution_id' => Institution::factory()->create()->id,
            // Único por ejecución: la base de pruebas acumula filas y un
            // correo fijo chocaba con `users_email_unique` en la 2.ª corrida.
            'email'          => ($duplicado = 'ya.existe.' . uniqid() . '@ejemplo.com'),
        ]);

        $csv = self::HEADER . "\n"
            . "Buena Fila,buena.fila@ejemplo.com,,VAL-0001,4A2026,active,2017-01-10,,,\n"
            . "Ade Mala,ade.mala@ejemplo.com,,VAL-0002,4A2026,active,,,,inventada\n"
            . "Padre Malo,padre.malo@ejemplo.com,,VAL-0003,4A2026,active,,Tutor,no-es-correo,\n"
            . "Fecha Mala,fecha.mala@ejemplo.com,,VAL-0004,4A2026,active,2017-02-30,,,\n"
            . "Correo Malo,no-es-correo,,VAL-0005,4A2026,active,,,,\n"
            . ",,,VAL-0006,4A2026,active,,,,\n"
            . ",sin.nombre@ejemplo.com,,VAL-0007,4A2026,active,,,,\n"
            . "Duplicado,{$duplicado},,VAL-0008,4A2026,active,,,,\n";

        $res = $this->subir($csv)->assertOk();

        $res->assertJson(['created' => 1, 'skipped' => 7]);

        $errores = implode("\n", $res->json('errors'));
        foreach ([
            '«Tipo de adecuación» inválido',
            '«Correo del tutor» inválido',
            '«Fecha de nacimiento» inválida',
            '«Correo institucional» inválido',
            'se requiere el «Correo institucional»',
            '«Nombre completo» es obligatorio',
            'ya está en uso',
        ] as $mensaje) {
            $this->assertStringContainsString($mensaje, $errores);
        }

        $this->assertDatabaseHas('users', ['email' => 'buena.fila@ejemplo.com']);
        $this->assertDatabaseMissing('users', ['email' => 'ade.mala@ejemplo.com']);
        $this->assertDatabaseMissing('users', ['email' => 'sin.nombre@ejemplo.com']);
        $this->assertSame(1, User::where('email', $existente->email)->count(), 'No se duplica la cuenta existente');
    }

    public function test_a_valid_adecuacion_is_stored_normalized(): void
    {
        $this->subir(self::HEADER . "\nCon Ade,con.ade@ejemplo.com,,VAL-0101,4A2026,active,,,,ACCESO\n")
            ->assertOk()->assertJson(['created' => 1]);

        $user = User::where('email', 'con.ade@ejemplo.com')->firstOrFail();
        $this->assertSame('acceso', Student::where('user_id', $user->id)->first()->adecuacion_type->value);
    }

    public function test_an_existing_student_is_found_by_student_code(): void
    {
        $user = User::factory()->student()->create(['institution_id' => $this->institution->id]);
        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $this->institution->id,
            'student_code'   => 'COD-EXISTE',
        ]);

        // Sin email ni user_id: el código basta para reconocerlo y actualizarlo,
        // en vez de intentar crear una cuenta nueva.
        $this->subir(self::HEADER . "\n,,,COD-EXISTE,4A2026,active,2017-06-01,,,\n")
            ->assertOk()->assertJson(['skipped' => 0, 'users_created' => 0]);

        $this->assertDatabaseHas('group_students', ['student_user_id' => $user->id, 'left_at' => null]);
    }
}
