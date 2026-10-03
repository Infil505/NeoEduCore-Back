<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Exportación de reportes a fichero: CSV y XLSX.
 *
 * Lo que se vigila aquí, más allá de que la descarga funcione:
 *
 *  1. **Que los dos formatos no diverjan.** Salen del mismo dataset, así que las
 *     cabeceras tienen que ser idénticas; el test las compara literalmente.
 *  2. **Que ninguna celda se convierta en fórmula.** Un nombre de alumno que
 *     empiece por `=` no debe ejecutarse al abrir el fichero. Es el agujero S4.
 *  3. **Que el XLSX lleve tipos reales**: la nota como número y la fecha como
 *     fecha, no como texto — si no, no se puede ordenar ni graficar en Excel.
 *  4. **Que el permiso sea el mismo que el del CSV**, no uno nuevo más laxo.
 */
class ReportExportsTest extends TestCase
{
    use ApiAuth;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Nombre hostil: al abrirlo en Excel, `=HYPERLINK(...)` se ejecutaría. */
    private const NOMBRE_HOSTIL = '=HYPERLINK("http://malo.example","clic")';

    public function test_el_xlsx_de_resultados_usa_las_mismas_cabeceras_que_el_csv(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examOf($teacher, $institution);

        $this->attemptFor($institution, $exam, $this->makeStudent($institution), 8, 10);

        $xlsx = $this->get("/api/reports/exams/{$exam->id}/results.xlsx");
        $xlsx->assertOk();
        $this->assertStringContainsString(self::XLSX_MIME, $xlsx->headers->get('content-type'));

        $csv = $this->get("/api/reports/exams/{$exam->id}/results.csv");
        $csv->assertOk();

        $this->assertSame(
            $this->cabecerasDelCsv($csv->streamedContent()),
            $this->filasDelXlsx($xlsx->streamedContent())[0],
            'El XLSX y el CSV dejaron de compartir dataset.'
        );
    }

    public function test_el_xlsx_de_historial_usa_las_mismas_cabeceras_que_el_csv(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $student     = $this->makeStudent($institution);
        $this->darAccesoDocenteA($teacher, $student->id, $institution->id);

        $this->attemptFor($institution, $exam = $this->examOf($teacher, $institution), $student, 8, 10);

        $xlsx = $this->get("/api/reports/students/{$student->id}/history.xlsx");
        $xlsx->assertOk();
        $this->assertStringContainsString(self::XLSX_MIME, $xlsx->headers->get('content-type'));

        $csv = $this->get("/api/reports/students/{$student->id}/history.csv");
        $csv->assertOk();

        $this->assertSame(
            $this->cabecerasDelCsv($csv->streamedContent()),
            $this->filasDelXlsx($xlsx->streamedContent())[0]
        );
    }

    /**
     * Las notas tienen que llegar como número y la fecha como fecha de Excel.
     * Si salieran como texto la descarga «funcionaría» igual, pero el docente no
     * podría ordenar por nota ni calcular un promedio sobre la columna.
     */
    public function test_el_xlsx_escribe_la_nota_como_numero_y_la_entrega_como_fecha(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examOf($teacher, $institution);

        $this->attemptFor($institution, $exam, $this->makeStudent($institution), 8, 10);

        $hoja = $this->hojaDelXlsx(
            $this->get("/api/reports/exams/{$exam->id}/results.xlsx")->streamedContent()
        );

        // C=score, D=max_score, E=percentage, F=submitted_at (fila 2)
        $this->assertSame(8.0, $hoja->getCell('C2')->getValue());
        $this->assertSame(10.0, $hoja->getCell('D2')->getValue());
        $this->assertSame(80.0, $hoja->getCell('E2')->getValue());

        $this->assertTrue(
            ExcelDate::isDateTime($hoja->getCell('F2')),
            'submitted_at no quedó como fecha de Excel.'
        );
    }

    /**
     * S4. El mismo nombre hostil, por los dos formatos.
     *
     * XLSX: la celda debe quedar de tipo texto, nunca `TYPE_FORMULA`.
     * CSV:  el texto sale precedido de un apóstrofo, que es como la hoja de
     *       cálculo entiende «esto no es una fórmula».
     */
    public function test_un_nombre_que_empieza_por_igual_no_se_ejecuta_como_formula(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examOf($teacher, $institution);

        $student = $this->makeStudent($institution, ['full_name' => self::NOMBRE_HOSTIL]);
        $this->attemptFor($institution, $exam, $student, 8, 10);

        $hoja = $this->hojaDelXlsx(
            $this->get("/api/reports/exams/{$exam->id}/results.xlsx")->streamedContent()
        );

        $celda = $hoja->getCell('B2');
        $this->assertSame(DataType::TYPE_STRING, $celda->getDataType(), 'El XLSX guardó el nombre como fórmula.');
        $this->assertSame(self::NOMBRE_HOSTIL, $celda->getValue());

        $csv = $this->primeraFilaDelCsv(
            $this->get("/api/reports/exams/{$exam->id}/results.csv")->streamedContent()
        );
        $this->assertSame("'" . self::NOMBRE_HOSTIL, $csv[1], 'El CSV dejó el nombre sin neutralizar.');
    }

    /** El título del examen recorre la otra rama del dataset (historial). */
    public function test_un_titulo_de_examen_hostil_tampoco_se_ejecuta(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $student     = $this->makeStudent($institution);
        $this->darAccesoDocenteA($teacher, $student->id, $institution->id);

        $exam = $this->examOf($teacher, $institution, ['title' => '=1+1']);
        $this->attemptFor($institution, $exam, $student, 5, 10);

        $hoja = $this->hojaDelXlsx(
            $this->get("/api/reports/students/{$student->id}/history.xlsx")->streamedContent()
        );

        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('C2')->getDataType());
        $this->assertSame('=1+1', $hoja->getCell('C2')->getValue());

        $csv = $this->primeraFilaDelCsv(
            $this->get("/api/reports/students/{$student->id}/history.csv")->streamedContent()
        );
        $this->assertSame("'=1+1", $csv[2]);
    }

    /** El XLSX no puede ser una puerta más ancha que el CSV. */
    public function test_el_docente_ajeno_al_examen_recibe_403_en_el_xlsx(): void
    {
        $institution = Institution::factory()->create();
        $otro        = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $exam        = $this->examOf($otro, $institution);

        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->get("/api/reports/exams/{$exam->id}/results.xlsx")->assertStatus(403);
    }

    public function test_el_docente_no_asignado_recibe_403_en_el_historial_xlsx(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $student = $this->makeStudent($institution);

        $this->get("/api/reports/students/{$student->id}/history.xlsx")->assertStatus(403);
    }

    /* =========================
     | Apoyo
     ========================= */

    private function makeStudent(Institution $institution, array $overrides = []): User
    {
        $user = User::factory()->student()->create(array_merge([
            'institution_id' => $institution->id,
        ], $overrides));

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
        ]);

        return $user;
    }

    private function examOf(User $teacher, Institution $institution, array $overrides = []): Exam
    {
        return Exam::factory()->create(array_merge([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ], $overrides));
    }

    private function attemptFor(Institution $institution, Exam $exam, User $student, float $score, float $max): void
    {
        ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'score'           => $score,
            'max_score'       => $max,
        ]);
    }

    /** @return array<int,string> */
    private function cabecerasDelCsv(string $contenido): array
    {
        return $this->lineaDelCsv($contenido, 0);
    }

    /** Primera fila de datos, ya sin escapado de comillas. @return array<int,string> */
    private function primeraFilaDelCsv(string $contenido): array
    {
        return $this->lineaDelCsv($contenido, 1);
    }

    /** @return array<int,string> */
    private function lineaDelCsv(string $contenido, int $indice): array
    {
        $lineas = explode("\n", str_replace("\r\n", "\n", ltrim($contenido, "\xEF\xBB\xBF")));

        return str_getcsv(rtrim($lineas[$indice], "\r"));
    }

    /** @return array<int,array<int,mixed>> */
    private function filasDelXlsx(string $binario): array
    {
        return $this->hojaDelXlsx($binario)->toArray(null, true, false, false);
    }

    private function hojaDelXlsx(string $binario): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'neoedu_') . '.xlsx';
        file_put_contents($ruta, $binario);

        $book = IOFactory::load($ruta);
        @unlink($ruta);

        return $book->getActiveSheet();
    }
}
