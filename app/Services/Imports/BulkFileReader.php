<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee los archivos de carga masiva (CSV o XLSX) y los convierte en registros
 * `clave_interna => valor`, indexados por su número de fila en el archivo para
 * que los mensajes de error apunten a la fila que ve el usuario.
 *
 * Lo comparten la carga de estudiantes y la de docentes/administradores.
 */
class BulkFileReader
{
    /**
     * @return array{0: array<int,array<string,mixed>>, 1: string|null} [registros, error]
     */
    public function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->readCsv($file);
        }

        if ($ext === 'xlsx') {
            return $this->readXlsx($file);
        }

        return [[], 'Formato no soportado. Use .csv o .xlsx'];
    }

    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [[], 'No se pudo leer el archivo CSV.'];
        }

        // Detectar y descartar BOM UTF-8
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Separador: la plantilla usa `;` (Excel en español), pero los archivos
        // antiguos o exportados con configuración inglesa usan `,`. Se decide
        // por la primera línea.
        $start     = ftell($handle);
        $firstLine = (string) fgets($handle);
        fseek($handle, $start);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $lines = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lines[] = $data;
        }

        fclose($handle);

        return [$this->linesToRecords($lines), null];
    }

    private function readXlsx(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            // Los datos van siempre en la primera hoja; las plantillas guardan
            // las listas de los desplegables en una hoja oculta detrás.
            $lines = $spreadsheet->getSheet(0)->toArray(null, true, true, false);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return [$this->linesToRecords($lines), null];
        } catch (\Exception $e) {
            return [[], 'No se pudo leer el archivo XLSX: ' . $e->getMessage()];
        }
    }

    /**
     * La cabecera no tiene por qué estar en la fila 1: la plantilla XLSX lleva
     * título y descripción encima. Se toma como cabecera la primera fila (de las
     * diez primeras) que nombre al menos dos columnas conocidas.
     *
     * @param array<int,array<int,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function linesToRecords(array $lines): array
    {
        $known = ['full_name', 'email', 'seccion', 'aula', 'user_id', 'student_code'];
        $toKeys = fn(array $line) => array_map(fn($h) => BulkTemplateService::columnForHeader((string) $h), $line);

        $headerIdx = 0;
        foreach (array_slice($lines, 0, 10, true) as $i => $line) {
            if (count(array_intersect($toKeys($line), $known)) >= 2) {
                $headerIdx = $i;
                break;
            }
        }

        if (!isset($lines[$headerIdx])) {
            return [];
        }

        // Acepta las cabeceras en español y las claves en inglés de las
        // plantillas antiguas.
        $header  = $toKeys($lines[$headerIdx]);
        $records = [];

        foreach (array_slice($lines, $headerIdx + 1, null, true) as $i => $line) {
            if ($this->isBlankOrInstructionRow($line)) {
                continue;
            }

            // Una fila más corta que la cabecera (columnas opcionales del final
            // sin escribir) se completa con vacíos; una más larga solo vale si
            // lo que sobra está vacío.
            $extra = array_slice($line, count($header));
            if (array_filter($extra, fn($v) => trim((string) $v) !== '') !== []) {
                continue; // mal formada — la saltamos silenciosamente
            }
            $line = array_pad(array_slice($line, 0, count($header)), count($header), '');

            $records[$i + 1] = array_combine($header, $line);
        }

        return $records;
    }

    /**
     * Filas que no son datos: vacías, o la fila de instrucciones de las
     * plantillas CSV antiguas (todas sus celdas entre paréntesis).
     *
     * @param array<int,mixed> $cells
     */
    private function isBlankOrInstructionRow(array $cells): bool
    {
        $filled = array_filter(
            array_map(fn($v) => trim((string) $v), $cells),
            fn($v) => $v !== ''
        );

        foreach ($filled as $value) {
            if (!str_starts_with($value, '(') || !str_ends_with($value, ')')) {
                return false;
            }
        }

        return true;
    }
}
