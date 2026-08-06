<?php

namespace App\Services\Admin;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de reportes académicos a fichero.
 *
 * Solo CSV: el PDF con gráficos se arma en el frontend a partir de los
 * agregados que devuelve `ReportMetricsService`. El backend no renderiza
 * documentos ni dibuja gráficos — expone datos.
 *
 * El CSV sí se genera aquí porque es una serialización del dataset completo,
 * no una pieza de presentación: se transmite fila a fila (`lazy()`) para que la
 * memoria no crezca con el número de estudiantes.
 */
class ReportExportService
{
    /**
     * @param  array<int,string>               $headers
     * @param  iterable<int,array<int,mixed>>  $rows
     */
    public function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8: sin él Excel en Windows rompe las tildes de los nombres.
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Resultados de un examen (reporte grupal), dataset completo. */
    public function examResultsCsv(Exam $exam): StreamedResponse
    {
        $headers = ['student_user_id', 'student_name', 'score', 'max_score', 'percentage', 'submitted_at'];

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

        return $this->streamCsv('exam_results_' . $exam->id . '.csv', $headers, $rows);
    }

    /** Historial de un estudiante (reporte individual), dataset completo. */
    public function studentHistoryCsv(Student $student): StreamedResponse
    {
        $headers = ['attempt_id', 'exam_id', 'exam_title', 'subject', 'score', 'max_score', 'percentage', 'submitted_at'];

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

        return $this->streamCsv('student_history_' . $student->user_id . '.csv', $headers, $rows);
    }
}
