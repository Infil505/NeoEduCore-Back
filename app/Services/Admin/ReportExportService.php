<?php

namespace App\Services\Admin;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de reportes académicos a fichero: CSV y XLSX.
 *
 * El PDF con gráficos se arma en el frontend a partir de los agregados que
 * devuelve `ReportMetricsService`. El backend no renderiza documentos ni dibuja
 * gráficos — expone datos.
 *
 * CSV y XLSX sí se generan aquí porque son serializaciones del dataset completo,
 * no piezas de presentación. Cada reporte define su dataset **una sola vez**
 * (`examResultsDataset()`, `studentHistoryDataset()`) y los dos formatos lo
 * consumen: así no puede pasar que el XLSX gane una columna que al CSV le falte.
 *
 * Diferencia de memoria entre ambos: el CSV se transmite fila a fila (`lazy()`)
 * y nunca crece; el XLSX **no puede transmitirse** —PhpSpreadsheet arma el libro
 * entero en memoria y lo comprime al final—, así que su coste sí es proporcional
 * al número de filas. Con los volúmenes previstos (≤ ~1.000 intentos por examen)
 * son pocos MB; si algún día un reporte creciera un orden de magnitud, el CSV
 * sigue siendo la salida segura.
 */
class ReportExportService
{
    /** Tipos de columna. Gobiernan cómo se escribe cada celda en el XLSX. */
    public const T_TEXT     = 'text';
    public const T_NUMBER   = 'number';
    public const T_DATETIME = 'datetime';

    /** Formato de fecha del XLSX. Igual que el del CSV y el JSON: UTC. */
    private const XLSX_DATE_FORMAT = 'yyyy-mm-dd hh:mm:ss';

    /**
     * Caracteres que convierten una celda de texto en fórmula al abrir el
     * fichero con Excel o LibreOffice. Un nombre de alumno que empiece por `=`
     * —`=HYPERLINK("http://…")`— se ejecutaría en la máquina de quien abre el
     * reporte. Se neutralizan anteponiendo un apóstrofo, que las hojas de
     * cálculo entienden como «esto es texto» y no muestran.
     */
    private const PREFIJOS_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /* =========================
     | Reportes
     ========================= */

    /** Resultados de un examen (reporte grupal), dataset completo. */
    public function examResultsCsv(Exam $exam): StreamedResponse
    {
        return $this->streamCsv($this->examResultsDataset($exam));
    }

    public function examResultsXlsx(Exam $exam): StreamedResponse
    {
        return $this->streamXlsx($this->examResultsDataset($exam), 'Resultados');
    }

    /** Historial de un estudiante (reporte individual), dataset completo. */
    public function studentHistoryCsv(Student $student): StreamedResponse
    {
        return $this->streamCsv($this->studentHistoryDataset($student));
    }

    public function studentHistoryXlsx(Student $student): StreamedResponse
    {
        return $this->streamXlsx($this->studentHistoryDataset($student), 'Historial');
    }

    /* =========================
     | Datasets (una definición por reporte, dos formatos)
     ========================= */

    /**
     * @return array{filename:string, headers:array<int,string>, types:array<int,string>, rows:iterable<int,array<int,mixed>>}
     */
    private function examResultsDataset(Exam $exam): array
    {
        // lazy() y NO cursor(): `cursor()` ignora el eager loading (necesita
        // conocer todos los ids de antemano y por diseño va fila a fila), así
        // que `with(['student.user'])` no se aplicaba y cada fila disparaba 2
        // queries — ~2000 extra en un examen de 1000 alumnos. `lazy()` mantiene
        // la memoria acotada igual, pero por lotes, y sí respeta el eager load.
        $rows = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->with(['student.user'])
            ->orderByDesc('score')
            ->lazy()
            ->map(fn (ExamAttempt $a) => [
                $a->student_user_id,
                $a->student?->user?->full_name,
                (float) $a->score,
                (float) $a->max_score,
                $a->percentage,
                $a->submitted_at,
            ]);

        return [
            'filename' => 'exam_results_' . $exam->id,
            'headers'  => ['student_user_id', 'student_name', 'score', 'max_score', 'percentage', 'submitted_at'],
            'types'    => [self::T_TEXT, self::T_TEXT, self::T_NUMBER, self::T_NUMBER, self::T_NUMBER, self::T_DATETIME],
            'rows'     => $rows,
        ];
    }

    /**
     * @return array{filename:string, headers:array<int,string>, types:array<int,string>, rows:iterable<int,array<int,mixed>>}
     */
    private function studentHistoryDataset(Student $student): array
    {
        $rows = ExamAttempt::query()
            ->where('student_user_id', $student->user_id)
            ->whereNotNull('submitted_at')
            ->with(['exam.subject'])
            ->orderByDesc('submitted_at')
            ->lazy()
            ->map(fn (ExamAttempt $a) => [
                $a->id,
                $a->exam_id,
                $a->exam?->title,
                $a->exam?->subject?->name,
                (float) $a->score,
                (float) $a->max_score,
                $a->percentage,
                $a->submitted_at,
            ]);

        return [
            'filename' => 'student_history_' . $student->user_id,
            'headers'  => ['attempt_id', 'exam_id', 'exam_title', 'subject', 'score', 'max_score', 'percentage', 'submitted_at'],
            'types'    => [
                self::T_TEXT, self::T_TEXT, self::T_TEXT, self::T_TEXT,
                self::T_NUMBER, self::T_NUMBER, self::T_NUMBER, self::T_DATETIME,
            ],
            'rows'     => $rows,
        ];
    }

    /* =========================
     | Serialización
     ========================= */

    /**
     * @param  array{filename:string, headers:array<int,string>, types:array<int,string>, rows:iterable<int,array<int,mixed>>}  $dataset
     */
    private function streamCsv(array $dataset): StreamedResponse
    {
        $filename = $dataset['filename'] . '.csv';

        return response()->streamDownload(function () use ($dataset) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8: sin él Excel en Windows rompe las tildes de los nombres.
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, $dataset['headers']);

            foreach ($dataset['rows'] as $row) {
                fputcsv($out, array_map(fn ($v) => $this->celdaCsv($v), $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  array{filename:string, headers:array<int,string>, types:array<int,string>, rows:iterable<int,array<int,mixed>>}  $dataset
     */
    private function streamXlsx(array $dataset, string $hoja): StreamedResponse
    {
        $filename = $dataset['filename'] . '.xlsx';

        return response()->streamDownload(function () use ($dataset, $hoja) {
            $book  = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle($hoja);

            foreach ($dataset['headers'] as $i => $header) {
                $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
            }
            $sheet->getStyle([1, 1, count($dataset['headers']), 1])->getFont()->setBold(true);

            $fila = 2;
            foreach ($dataset['rows'] as $row) {
                foreach ($row as $i => $valor) {
                    $this->escribirCelda($sheet, $i + 1, $fila, $valor, $dataset['types'][$i] ?? self::T_TEXT);
                }
                $fila++;
            }

            foreach (array_keys($dataset['headers']) as $i) {
                $sheet->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
            }

            (new XlsxWriter($book))->save('php://output');

            // Octane: el worker sobrevive a la petición, así que un libro sin
            // desconectar deja las hojas referenciándose entre sí y no se libera.
            $book->disconnectWorksheets();
            unset($book);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /* =========================
     | Celdas
     ========================= */

    /**
     * El CSV no tiene tipos: todo sale como texto. Lo único que hay que hacer es
     * impedir que la hoja de cálculo que lo abra interprete una celda como
     * fórmula. Solo se toca el texto: los números ya llegan como `float` y un
     * negativo (`-3`) debe seguir siendo un número.
     */
    private function celdaCsv(mixed $valor): mixed
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        if (!is_string($valor) || $valor === '') {
            return $valor;
        }

        return in_array($valor[0], self::PREFIJOS_DE_FORMULA, true) ? "'" . $valor : $valor;
    }

    /**
     * Cada celda con su tipo explícito. `setCellValue()` no vale: adivina el
     * tipo, y a todo texto que empiece por `=` lo guarda como **fórmula** —el
     * mismo agujero del CSV, pero dentro del fichero. `setCellValueExplicit()`
     * con `TYPE_STRING` lo deja como texto sin necesidad de apóstrofo.
     */
    private function escribirCelda(Worksheet $sheet, int $col, int $fila, mixed $valor, string $tipo): void
    {
        if ($valor === null || $valor === '') {
            return; // celda vacía: nada que escribir
        }

        if ($tipo === self::T_NUMBER) {
            $sheet->setCellValueExplicit([$col, $fila], (float) $valor, DataType::TYPE_NUMERIC);

            return;
        }

        if ($tipo === self::T_DATETIME && $valor instanceof \DateTimeInterface) {
            $sheet->setCellValueExplicit([$col, $fila], ExcelDate::PHPToExcel($valor), DataType::TYPE_NUMERIC);
            $sheet->getStyle([$col, $fila])->getNumberFormat()->setFormatCode(self::XLSX_DATE_FORMAT);

            return;
        }

        $sheet->setCellValueExplicit([$col, $fila], (string) $valor, DataType::TYPE_STRING);
    }
}
